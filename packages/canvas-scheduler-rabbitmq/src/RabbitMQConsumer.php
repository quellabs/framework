<?php
	
	namespace Quellabs\Canvas\Scheduler\RabbitMQ;
	
	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Contracts\Scheduler\ConsumerInterface;
	use Quellabs\Support\ComposerUtils;
	
	/**
	 * RabbitMQ queue consumer.
	 * Discovered via the "scheduler" Composer family and selected
	 * by Sculpt when --consumer=rabbitmq is specified.
	 */
	class RabbitMQConsumer implements ConsumerInterface {
		
		/**
		 * @var array<string, mixed>
		 */
		private array $config = [];
		
		/**
		 * Returns the identifier used to select this consumer via CLI
		 * @return string
		 */
		public static function getName(): string {
			return 'rabbitmq';
		}
		
		/**
		 * Returns metadata about this consumer for discovery
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'name'        => self::getName(),
				'description' => 'RabbitMQ-backed job queue consumer',
			];
		}
		
		/**
		 * Returns the configuration
		 * @return array<string, mixed>
		 */
		public function getConfig(): array {
			return $this->config;
		}
		
		/**
		 * Sets configuration
		 * @param array<string, mixed> $config
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = $config;
		}
		
		/**
		 * Start the RabbitMQ consumer and begin processing jobs.
		 * Loads its own configuration from app.php.
		 * @return void
		 */
		public function run(): void {
			$projectRoot = ComposerUtils::getProjectRoot();
			
			if (file_exists($projectRoot . '/config/app.php')) {
				$appConfig = require $projectRoot . '/config/app.php';
			} else {
				$appConfig = [];
			}
			
			$queueName = $appConfig['queue_name'] ?? 'default';
			$maxJobs = $appConfig['queue_max_jobs'] ?? 500;
			$exchangeName = $appConfig['exchange_name'] ?? '';
			$prefetch = $appConfig['prefetch_count'] ?? 1;
			$rabbitConfig = $appConfig['rabbitmq'] ?? [];
			
			$connection = new AMQPStreamConnection(
				$rabbitConfig['host'] ?? '127.0.0.1',
				$rabbitConfig['port'] ?? 5672,
				$rabbitConfig['user'] ?? 'guest',
				$rabbitConfig['password'] ?? 'guest',
				$rabbitConfig['vhost'] ?? '/'
			);
			
			// Limit unacknowledged messages in flight; 1 gives fairest work distribution
			// across multiple worker processes
			$channel = $connection->channel();
			$channel->basic_qos(0, $prefetch, false);
			$channel->close();
			
			$kernel = new Kernel($appConfig);
			$container = $kernel->getDependencyInjector();
			$queue = new RabbitMQQueue($connection, $queueName, $exchangeName);
			$worker = new RabbitMQWorker($queue, $container);
			
			$worker->work($maxJobs);
		}
	}