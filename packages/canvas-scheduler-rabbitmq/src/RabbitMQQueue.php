<?php
	
	namespace Quellabs\Canvas\Scheduler\RabbitMQ;
	
	use PhpAmqpLib\Channel\AMQPChannel;
	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use PhpAmqpLib\Message\AMQPMessage;
	use Quellabs\Contracts\Scheduler\QueueableInterface;
	use Quellabs\Contracts\Scheduler\QueueInterface;
	
	/**
	 * RabbitMQ-backed job queue.
	 *
	 * Uses a single durable queue with persistent messages so jobs survive
	 * a broker restart. The default exchange (empty string) is used, which
	 * routes messages directly to the queue by name — no exchange declaration
	 * is required and no additional topology needs to be managed.
	 *
	 * Failed jobs that have exhausted their retry allowance are published to
	 * a separate failed-job queue (<queue_name>.failed) for inspection.
	 *
	 * Delivery tags are tracked internally so acknowledge() and fail() can
	 * issue the correct basic.ack / basic.nack without the caller needing to
	 * hold onto AMQP internals.
	 *
	 * Key layout (queue names):
	 *   {queue_name}         — pending jobs
	 *   {queue_name}.failed  — jobs that exceeded max retries
	 */
	class RabbitMQQueue implements QueueInterface {
		
		private AMQPStreamConnection $connection;
		private AMQPChannel $channel;
		private string $queueName;
		private string $exchangeName;
		
		/**
		 * Maps job ID → AMQP delivery tag for the currently reserved message.
		 * Only one message per job ID is in-flight at a time, so this map stays
		 * small (bounded by prefetch_count).
		 * @var array<string, int>
		 */
		private array $deliveryTags = [];
		
		/**
		 * RabbitMQQueue constructor
		 * @param AMQPStreamConnection $connection
		 * @param string $queueName Name of the primary pending queue
		 * @param string $exchangeName Exchange to publish to ('': default exchange)
		 * @param int $prefetchCount Maximum unacknowledged messages per worker (QoS)
		 */
		public function __construct(
			AMQPStreamConnection $connection,
			string $queueName = 'default',
			string $exchangeName = '',
			int $prefetchCount = 1
		) {
			$this->connection = $connection;
			$this->queueName = $queueName;
			$this->exchangeName = $exchangeName;
			$this->channel = $connection->channel();
			
			// Apply QoS on this channel so prefetch limits unacknowledged messages
			// in flight. QoS is channel-scoped in RabbitMQ, so this must be called
			// on the same channel used for basic_get/basic_ack.
			$this->channel->basic_qos(0, $prefetchCount, false);
			
			$this->declareQueues();
		}
		
		/**
		 * Push a job onto the pending queue
		 * @param QueueableInterface $job
		 * @return void
		 */
		public function push(QueueableInterface $job): void {
			$envelope = JobEnvelope::fromJob($job);
			$this->publish($this->queueName, $envelope->toJson());
		}
		
		/**
		 * Attempt to consume one message from the queue without blocking.
		 * Returns null immediately if no message is available.
		 *
		 * php-amqplib's basic_get is used rather than basic_consume so the
		 * worker can control its own polling loop without callback inversion.
		 * @return JobEnvelope|null
		 */
		public function pop(): ?JobEnvelope {
			// basic_get returns AMQPMessage|null (no_ack=false: we ack manually)
			$message = $this->channel->basic_get($this->queueName, false);
			
			if ($message === null) {
				return null;
			}
			
			try {
				$envelope = JobEnvelope::fromJson($message->body);
			} catch (\InvalidArgumentException $e) {
				// Malformed message — reject without requeue so it does not loop
				$this->channel->basic_reject($message->getDeliveryTag(), false);
				return null;
			}
			
			// Remember the delivery tag so acknowledge() / fail() can use it
			$this->deliveryTags[$envelope->id] = $message->getDeliveryTag();
			
			return $envelope;
		}
		
		/**
		 * Acknowledge successful job execution
		 * @param JobEnvelope $envelope
		 * @return void
		 */
		public function acknowledge(JobEnvelope $envelope): void {
			$tag = $this->deliveryTags[$envelope->id] ?? null;
			
			if ($tag === null) {
				return;
			}
			
			$this->channel->basic_ack($tag);
			unset($this->deliveryTags[$envelope->id]);
		}
		
		/**
		 * Handle a failed job — retry if attempts remain, otherwise move to the
		 * failed queue. In both cases the original message is rejected without
		 * requeue; a fresh message is published so the attempt count is updated.
		 * @param JobEnvelope $envelope
		 * @return void
		 */
		public function fail(JobEnvelope $envelope): void {
			$tag = $this->deliveryTags[$envelope->id] ?? null;
			
			// Reject the original message without requeue regardless of retry decision
			if ($tag !== null) {
				$this->channel->basic_reject($tag, false);
				unset($this->deliveryTags[$envelope->id]);
			}
			
			$incremented = $envelope->withIncrementedAttempts();
			
			if ($incremented->hasExceededMaxRetries()) {
				// Publish to the failed queue for inspection
				$this->publish($this->failedQueueName(), $incremented->toJson());
			} else {
				// Requeue with incremented attempt count
				$this->publish($this->queueName, $incremented->toJson());
			}
		}
		
		/**
		 * Returns the number of messages ready in the pending queue.
		 * Uses passive declare so no queue is created; throws if the queue
		 * does not exist.
		 * @return int
		 */
		public function size(): int {
			$result = $this->channel->queue_declare(
				$this->queueName,
				true,   // passive — inspect only, do not create
				true,   // durable
				false,  // exclusive
				false   // auto_delete
			);
			
			return (int)($result[1] ?? 0);
		}
		
		/**
		 * Returns the number of messages in the failed queue
		 * @return int
		 */
		public function failedCount(): int {
			$result = $this->channel->queue_declare(
				$this->failedQueueName(),
				true,   // passive
				true,   // durable
				false,
				false
			);
			
			return (int)($result[1] ?? 0);
		}
		
		/**
		 * Close the channel and connection cleanly
		 * @return void
		 */
		public function close(): void {
			$this->channel->close();
			$this->connection->close();
		}
		
		/**
		 * Declare the pending and failed queues as durable so they survive
		 * a broker restart. Idempotent — safe to call on every connection.
		 * @return void
		 */
		private function declareQueues(): void {
			// Pending queue
			$this->channel->queue_declare(
				$this->queueName,
				false,  // passive
				true,   // durable
				false,  // exclusive
				false   // auto_delete
			);
			
			// Failed queue
			$this->channel->queue_declare(
				$this->failedQueueName(),
				false,
				true,
				false,
				false
			);
		}
		
		/**
		 * Publish a JSON payload to the given queue via the configured exchange.
		 * Messages are marked persistent (delivery_mode=2) so they survive a
		 * broker restart.
		 * @param string $queue
		 * @param string $json
		 * @return void
		 */
		private function publish(string $queue, string $json): void {
			$message = new AMQPMessage($json, [
				'content_type'  => 'application/json',
				'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
			]);
			
			// When exchange is '' (default exchange), the routing key is the queue name
			$this->channel->basic_publish($message, $this->exchangeName, $queue);
		}
		
		/**
		 * Returns the name of the failed queue
		 * @return string
		 */
		private function failedQueueName(): string {
			return $this->queueName . '.failed';
		}
	}