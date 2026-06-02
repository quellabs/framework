<?php
	
	namespace Quellabs\Canvas\Scheduler\RabbitMQ;
	
	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Contracts\Scheduler\ConsumerInterface;
	
	/**
	 * RabbitMQ queue consumer.
	 * Discovered via the "scheduler" Composer family and selected
	 * by Sculpt when --consumer=rabbitmq is specified.
	 *
	 * Configuration is injected via setConfig() by the discovery mechanism
	 * before run() is called. ServiceProvider::getDefaults() provides fallback
	 * values for any keys not present in the injected config.
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
		 * @return void
		 */
		public function run(): void {
			$config = array_merge(ServiceProvider::getDefaults(), $this->config);
			
			$connection = new AMQPStreamConnection(
				(string)$config['host'],
				(int)$config['port'],
				(string)$config['user'],
				(string)$config['password'],
				(string)$config['vhost']
			);
			
			$kernel = new Kernel();
			$container = $kernel->getDependencyInjector();
			$queue     = new RabbitMQQueue($connection, (string)$config['queue_name'], (string)$config['exchange_name'], (int)$config['prefetch_count']);
			$worker = new RabbitMQWorker($queue, $container);
			
			$worker->work((int)$config['queue_max_jobs']);
		}
	}