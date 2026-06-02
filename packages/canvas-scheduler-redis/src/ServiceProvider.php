<?php
	
	namespace Quellabs\Canvas\Scheduler\Redis;
	
	use Predis\Client;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\Scheduler\QueueInterface;
	
	/**
	 * Service provider for the Redis queue.
	 * Binds QueueInterface to RedisQueue using connection settings
	 * from config/scheduler-redis.php.
	 *
	 * @phpstan-type RedisConfig array{
	 *     scheme: string,
	 *     host: string,
	 *     port: int,
	 *     queue_name: string,
	 *     queue_prefix: string,
	 *     queue_max_jobs: int,
	 *     queue_timeout: int
	 * }
	 */
	class ServiceProvider extends \Quellabs\DependencyInjection\Provider\ServiceProvider {
		
		/**
		 * @var RedisQueue|null Singleton instance
		 */
		private static ?RedisQueue $instance = null;
		
		/**
		 * Returns the provider's metadata for service discovery
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'provider' => 'redis',
				'type'     => 'queue',
			];
		}
		
		/**
		 * Returns default Redis settings
		 * @return RedisConfig
		 */
		public static function getDefaults(): array {
			return [
				'scheme'         => 'tcp',
				'host'           => '127.0.0.1',
				'port'           => 6379,
				'queue_name'     => 'default',
				'queue_prefix'   => 'canvas',
				'queue_max_jobs' => 500,
				'queue_timeout'  => 5,
			];
		}
		
		/**
		 * Resolve and type-normalize a raw config array against defaults.
		 * Used by both the service provider and the consumer so type coercion
		 * lives in one place.
		 * @param array<string, mixed> $raw
		 * @return RedisConfig
		 */
		public static function resolveConfig(array $raw): array {
			$defaults = self::getDefaults();
			
			return [
				'scheme'         => is_string($raw['scheme'] ?? null) ? $raw['scheme'] : $defaults['scheme'],
				'host'           => is_string($raw['host'] ?? null) ? $raw['host'] : $defaults['host'],
				'port'           => is_int($raw['port'] ?? null) ? $raw['port'] : $defaults['port'],
				'queue_name'     => is_string($raw['queue_name'] ?? null) ? $raw['queue_name'] : $defaults['queue_name'],
				'queue_prefix'   => is_string($raw['queue_prefix'] ?? null) ? $raw['queue_prefix'] : $defaults['queue_prefix'],
				'queue_max_jobs' => is_int($raw['queue_max_jobs'] ?? null) ? $raw['queue_max_jobs'] : $defaults['queue_max_jobs'],
				'queue_timeout'  => is_int($raw['queue_timeout'] ?? null) ? $raw['queue_timeout'] : $defaults['queue_timeout'],
			];
		}
		
		/**
		 * Merge injected config over defaults
		 * @return RedisConfig
		 */
		private function mergeConfig(): array {
			return self::resolveConfig($this->getConfig());
		}
		
		/**
		 * Returns true if this provider handles QueueInterface.
		 * Supports explicit selection via context metadata or config queue_driver key.
		 * @param string $className
		 * @param array<string, mixed> $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			if ($className !== QueueInterface::class) {
				return false;
			}
			
			// Priority 1: Explicit provider request via container context
			if (!empty($metadata['context'])) {
				return $metadata['context'] === 'redis';
			}
			
			// Priority 2: Check config for preferred queue driver
			if ($this->hasConfigValue('queue_driver')) {
				return $this->getConfigValue('queue_driver') === 'redis';
			}
			
			return true;
		}
		
		/**
		 * Creates and returns a configured RedisQueue instance
		 * @param string $className
		 * @param array<string, mixed> $dependencies
		 * @param array<string, mixed> $metadata
		 * @param MethodContextInterface|null $methodContext
		 * @return object
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			$config = $this->mergeConfig();
			
			$redis = new Client([
				'scheme' => $config['scheme'],
				'host'   => $config['host'],
				'port'   => $config['port'],
			]);
			
			return self::$instance = new RedisQueue($redis, $config['queue_name'], $config['queue_prefix']);
		}
	}