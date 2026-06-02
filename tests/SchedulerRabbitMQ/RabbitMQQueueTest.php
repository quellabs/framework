<?php
	
	namespace Quellabs\CanvasSchedulerRabbitMQ\Tests;
	
	use PhpAmqpLib\Channel\AMQPChannel;
	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use PhpAmqpLib\Message\AMQPMessage;
	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Scheduler\RabbitMQ\JobEnvelope;
	use Quellabs\Canvas\Scheduler\RabbitMQ\RabbitMQQueue;
	use Quellabs\Contracts\Scheduler\QueueableInterface;
	
	/**
	 * Minimal QueueableInterface stub for push() tests.
	 */
	class QueueableStub implements QueueableInterface {
		public function handle(): void {}
		public function getPayload(): array { return ['key' => 'value']; }
		public function getMaxRetries(): int { return 3; }
		public function getTimeout(): int { return 60; }
	}
	
	class RabbitMQQueueTest extends TestCase {
		
		private AMQPChannel&MockObject $channel;
		private AMQPStreamConnection&MockObject $connection;
		private RabbitMQQueue $queue;
		
		protected function setUp(): void {
			$this->channel = $this->createMock(AMQPChannel::class);
			
		// declareQueues() is called in the constructor but its return value is not
		// used, so no stub is needed here. Tests that call size() or failedCount()
		// set up their own queue_declare stub.
			$this->connection = $this->createMock(AMQPStreamConnection::class);
			$this->connection->method('channel')->willReturn($this->channel);
			
			$this->queue = new RabbitMQQueue($this->connection, 'jobs', '');
		}
		
		// -------------------------------------------------------------------------
		// push
		// -------------------------------------------------------------------------
		
		public function testPushCallsBasicPublishWithQueueName(): void {
			$this->channel
				->expects($this->once())
				->method('basic_publish')
				->with(
					$this->isInstanceOf(AMQPMessage::class),
					'',        // exchangeName
					'jobs'     // routing key = queue name when using default exchange
				);
			
			$this->queue->push(new QueueableStub());
		}
		
		public function testPushPublishesPersistentMessage(): void {
			$capturedMessage = null;
			
			$this->channel
				->method('basic_publish')
				->willReturnCallback(function (AMQPMessage $msg) use (&$capturedMessage) {
					$capturedMessage = $msg;
				});
			
			$this->queue->push(new QueueableStub());
			
			$this->assertNotNull($capturedMessage);
			$this->assertSame(
				AMQPMessage::DELIVERY_MODE_PERSISTENT,
				$capturedMessage->get('delivery_mode')
			);
		}
		
		public function testPushPublishesValidJsonBody(): void {
			$capturedMessage = null;
			
			$this->channel
				->method('basic_publish')
				->willReturnCallback(function (AMQPMessage $msg) use (&$capturedMessage) {
					$capturedMessage = $msg;
				});
			
			$this->queue->push(new QueueableStub());
			
			$decoded = json_decode($capturedMessage->body, true);
			$this->assertIsArray($decoded);
			$this->assertArrayHasKey('id', $decoded);
			$this->assertArrayHasKey('class', $decoded);
			$this->assertArrayHasKey('payload', $decoded);
		}
		
		// -------------------------------------------------------------------------
		// pop — empty queue
		// -------------------------------------------------------------------------
		
		public function testPopReturnsNullWhenQueueIsEmpty(): void {
			$this->channel->method('basic_get')->willReturn(null);
			
			$this->assertNull($this->queue->pop());
		}
		
		// -------------------------------------------------------------------------
		// pop — malformed message
		// -------------------------------------------------------------------------
		
		public function testPopRejectsAndReturnsNullOnMalformedJson(): void {
			$amqpMessage = new AMQPMessage('not valid json');
			$amqpMessage->setDeliveryInfo(42, false, 'jobs', 'jobs');
			
			$this->channel->method('basic_get')->willReturn($amqpMessage);
			
			$this->channel
				->expects($this->once())
				->method('basic_reject')
				->with(42, false);  // requeue=false
			
			$this->assertNull($this->queue->pop());
		}
		
		public function testPopRejectsWithoutRequeueOnMalformedMessage(): void {
			$amqpMessage = new AMQPMessage('{"id":"x","class":"Missing\\\\Class"}');
			$amqpMessage->setDeliveryInfo(99, false, 'jobs', 'jobs');
			
			$this->channel->method('basic_get')->willReturn($amqpMessage);
			
			// fromJson throws InvalidArgumentException (missing fields or bad class)
			$this->channel
				->expects($this->once())
				->method('basic_reject')
				->with(99, false);
			
			$this->assertNull($this->queue->pop());
		}
		
		// -------------------------------------------------------------------------
		// pop — valid message
		// -------------------------------------------------------------------------
		
		public function testPopReturnsEnvelopeOnValidMessage(): void {
			$envelope = new JobEnvelope(QueueableStub::class, ['key' => 'value'], 0, 3, 60, 'test-id', 0);
			$amqpMessage = new AMQPMessage($envelope->toJson());
			$amqpMessage->setDeliveryInfo(7, false, 'jobs', 'jobs');
			
			$this->channel->method('basic_get')->willReturn($amqpMessage);
			
			$result = $this->queue->pop();
			
			$this->assertInstanceOf(JobEnvelope::class, $result);
			$this->assertSame('test-id', $result->id);
		}
		
		public function testPopDoesNotAckImmediately(): void {
			$envelope = new JobEnvelope(QueueableStub::class, [], 0, 3, 60, 'test-id', 0);
			$amqpMessage = new AMQPMessage($envelope->toJson());
			$amqpMessage->setDeliveryInfo(7, false, 'jobs', 'jobs');
			
			$this->channel->method('basic_get')->willReturn($amqpMessage);
			
			$this->channel->expects($this->never())->method('basic_ack');
			
			$this->queue->pop();
		}
		
		// -------------------------------------------------------------------------
		// acknowledge
		// -------------------------------------------------------------------------
		
		public function testAcknowledgeSendsBasicAckWithStoredTag(): void {
			$envelope = new JobEnvelope(QueueableStub::class, [], 0, 3, 60, 'ack-id', 0);
			$amqpMessage = new AMQPMessage($envelope->toJson());
			$amqpMessage->setDeliveryInfo(55, false, 'jobs', 'jobs');
			
			$this->channel->method('basic_get')->willReturn($amqpMessage);
			$this->queue->pop();
			
			$this->channel
				->expects($this->once())
				->method('basic_ack')
				->with(55);
			
			$this->queue->acknowledge($envelope);
		}
		
		public function testAcknowledgeRemovesDeliveryTagFromMap(): void {
			$envelope = new JobEnvelope(QueueableStub::class, [], 0, 3, 60, 'ack-id', 0);
			$amqpMessage = new AMQPMessage($envelope->toJson());
			$amqpMessage->setDeliveryInfo(55, false, 'jobs', 'jobs');
			
			$this->channel->method('basic_get')->willReturn($amqpMessage);
			$this->queue->pop();
			$this->queue->acknowledge($envelope);
			
			// Second acknowledge for the same envelope should be a no-op (tag no longer stored)
			$this->channel->expects($this->never())->method('basic_ack');
			$this->queue->acknowledge($envelope);
		}
		
		public function testAcknowledgeIsNoOpForUnknownEnvelope(): void {
			$unknown = new JobEnvelope(QueueableStub::class, [], 0, 3, 60, 'ghost-id', 0);
			
			// Must not throw or call basic_ack
			$this->channel->expects($this->never())->method('basic_ack');
			$this->queue->acknowledge($unknown);
		}
		
		// -------------------------------------------------------------------------
		// fail — retry path
		// -------------------------------------------------------------------------
		
		public function testFailRejectsOriginalMessageWithoutRequeue(): void {
			$envelope = new JobEnvelope(QueueableStub::class, [], 0, 3, 60, 'fail-id', 0);
			$amqpMessage = new AMQPMessage($envelope->toJson());
			$amqpMessage->setDeliveryInfo(10, false, 'jobs', 'jobs');
			
			$this->channel->method('basic_get')->willReturn($amqpMessage);
			$this->queue->pop();
			
			$this->channel
				->expects($this->once())
				->method('basic_reject')
				->with(10, false);
			
			$this->channel->method('basic_publish');
			$this->queue->fail($envelope);
		}
		
		public function testFailRepublishesToMainQueueWhenRetriesRemain(): void {
			// attempts=0, maxRetries=3 → still has retries
			$envelope = new JobEnvelope(QueueableStub::class, [], 0, 3, 60, 'retry-id', 0);
			$amqpMessage = new AMQPMessage($envelope->toJson());
			$amqpMessage->setDeliveryInfo(10, false, 'jobs', 'jobs');
			
			$this->channel->method('basic_get')->willReturn($amqpMessage);
			$this->queue->pop();
			
			$this->channel
				->expects($this->once())
				->method('basic_publish')
				->with(
					$this->isInstanceOf(AMQPMessage::class),
					'',
					'jobs'  // main queue, not failed queue
				);
			
			$this->queue->fail($envelope);
		}
		
		public function testFailRepublishesWithIncrementedAttemptCount(): void {
			$envelope = new JobEnvelope(QueueableStub::class, [], 1, 3, 60, 'retry-id', 0);
			$amqpMessage = new AMQPMessage($envelope->toJson());
			$amqpMessage->setDeliveryInfo(10, false, 'jobs', 'jobs');
			
			$this->channel->method('basic_get')->willReturn($amqpMessage);
			$this->queue->pop();
			
			$capturedMessage = null;
			$this->channel
				->method('basic_publish')
				->willReturnCallback(function (AMQPMessage $msg) use (&$capturedMessage) {
					$capturedMessage = $msg;
				});
			
			$this->queue->fail($envelope);
			
			$decoded = json_decode($capturedMessage->body, true);
			$this->assertSame(2, $decoded['attempts']);
		}
		
		// -------------------------------------------------------------------------
		// fail — dead-letter path
		// -------------------------------------------------------------------------
		
		public function testFailMovesToFailedQueueWhenRetriesExhausted(): void {
			// attempts=3, maxRetries=3 → withIncrementedAttempts → attempts=4 → exceeded
			$envelope = new JobEnvelope(QueueableStub::class, [], 3, 3, 60, 'exhausted-id', 0);
			$amqpMessage = new AMQPMessage($envelope->toJson());
			$amqpMessage->setDeliveryInfo(20, false, 'jobs', 'jobs');
			
			$this->channel->method('basic_get')->willReturn($amqpMessage);
			$this->queue->pop();
			
			$this->channel
				->expects($this->once())
				->method('basic_publish')
				->with(
					$this->isInstanceOf(AMQPMessage::class),
					'',
					'jobs.failed'  // dead-letter queue
				);
			
			$this->queue->fail($envelope);
		}
		
		// -------------------------------------------------------------------------
		// fail — missing tag (envelope never popped)
		// -------------------------------------------------------------------------
		
		public function testFailHandlesUnknownEnvelopeWithoutCrashing(): void {
			$unknown = new JobEnvelope(QueueableStub::class, [], 0, 3, 60, 'ghost-id', 0);
			
			// No basic_reject since there's no tracked tag, but publish still happens
			$this->channel->expects($this->never())->method('basic_reject');
			$this->channel->method('basic_publish');
			
			// Must not throw
			$this->queue->fail($unknown);
		}
		
		// -------------------------------------------------------------------------
		// size / failedCount
		// -------------------------------------------------------------------------
		
		public function testSizeReturnsMessageCount(): void {
			$this->channel
				->method('queue_declare')
				->willReturnCallback(function (string $name, bool $passive) {
					return [$name, $passive ? 42 : 0, 0];
				});
			
			$this->assertSame(42, $this->queue->size());
		}
		
		public function testFailedCountReturnsMessageCount(): void {
			$this->channel
				->method('queue_declare')
				->willReturnCallback(function (string $name, bool $passive) {
					return [$name, $passive ? 7 : 0, 0];
				});
			
			$this->assertSame(7, $this->queue->failedCount());
		}
	}