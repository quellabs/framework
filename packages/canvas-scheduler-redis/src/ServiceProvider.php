<?php
	
	namespace Quellabs\Canvas\Scheduler\Consumers\Redis;
	
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
	 *     prefix: string
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
		 * Returns default Redis connection settings
		 * @return RedisConfig
		 */
		public static function getDefaults(): array {
			return [
				'scheme'     => 'tcp',
				'host'       => '127.0.0.1',
				'port'       => 6379,
				'queue_name' => 'default',
				'prefix'     => 'canvas',
			];
		}
		
		/**
		 * Merge user config over defaults
		 * @return RedisConfig
		 */
		private function mergeConfig(): array {
			$defaults = self::getDefaults();
			$config = $this->getConfig();
			
			return [
				'scheme'     => $this->normalizeString($config['scheme'] ?? null, $defaults['scheme']),
				'host'       => $this->normalizeString($config['host'] ?? null, $defaults['host']),
				'port'       => $this->normalizeInt($config['port'] ?? null, $defaults['port']),
				'queue_name' => $this->normalizeString($config['queue_name'] ?? null, $defaults['queue_name']),
				'prefix'     => $this->normalizeString($config['prefix'] ?? null, $defaults['prefix']),
			];
		}
		
		/**
		 * Returns true if this provider handles QueueInterface.
		 * Supports explicit selection via context metadata or config/app.php queue_driver key.
		 * @param string $className
		 * @param array<string, mixed> $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			if ($className !== QueueInterface::class) {
				return false;
			}
			
			// Priority 1: Explicit provider request via container context
			if (!empty($metadata['provider'])) {
				return $metadata['provider'] === 'redis';
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
			
			return self::$instance = new RedisQueue($redis, $config['queue_name'], $config['prefix']);
		}
	}