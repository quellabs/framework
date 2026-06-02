<?php
	
	namespace Quellabs\Canvas\Scheduler\Redis;
	
	use Predis\Client;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Contracts\Scheduler\ConsumerInterface;
	
	/**
	 * Redis queue consumer.
	 * Discovered via the "scheduler" Composer family and selected
	 * by Sculpt when --consumer=redis is specified.
	 *
	 * Configuration is injected via setConfig() by the discovery mechanism
	 * before run() is called. ServiceProvider::getDefaults() provides fallback
	 * values for any keys not present in the injected config.
	 */
	class RedisConsumer implements ConsumerInterface {
		
		/**
		 * RedisConsumer constructor
		 * @var array<string, mixed>
		 */
		private array $config = [];
		
		/**
		 * Returns the identifier used to select this consumer via CLI
		 * @return string
		 */
		public static function getName(): string {
			return 'redis';
		}
		
		/**
		 * Returns metadata about this consumer for discovery
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'name'        => self::getName(),
				'description' => 'Redis-backed job queue consumer',
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
		 * Start the Redis consumer and begin processing jobs.
		 * @return void
		 */
		public function run(): void {
			$config = array_merge(ServiceProvider::getDefaults(), $this->config);
			
			$redis = new Client([
				'scheme' => (string)$config['scheme'],
				'host'   => (string)$config['host'],
				'port'   => (int)$config['port'],
			]);
			
			$kernel = new Kernel();
			$container = $kernel->getDependencyInjector();
			$queue     = new RedisQueue($redis, (string)$config['queue_name'], (string)$config['queue_prefix']);
			$worker = new RedisWorker($queue, $container);
			
			$worker->work((int)$config['queue_max_jobs'], (int)$config['queue_timeout']);
		}
	}