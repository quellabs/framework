<?php
	
	namespace Quellabs\Canvas\Scheduler\RabbitMQ;
	
	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\Scheduler\QueueInterface;
	
	/**
	 * Service provider for the RabbitMQ queue.
	 * Binds QueueInterface to RabbitMQQueue using connection settings
	 * from config/scheduler-rabbitmq.php.
	 *
	 * @phpstan-type RabbitMQConfig array{
	 *     host: string,
	 *     port: int,
	 *     user: string,
	 *     password: string,
	 *     vhost: string,
	 *     queue_name: string,
	 *     exchange_name: string,
	 *     prefetch_count: int,
	 *     queue_max_jobs: int
	 * }
	 */
	class ServiceProvider extends \Quellabs\DependencyInjection\Provider\ServiceProvider {
		
		/**
		 * @var RabbitMQQueue|null Singleton instance
		 */
		private static ?RabbitMQQueue $instance = null;
		
		/**
		 * Returns the provider's metadata for service discovery
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'provider' => 'rabbitmq',
				'type'     => 'queue',
			];
		}
		
		/**
		 * Returns default RabbitMQ settings
		 * @return RabbitMQConfig
		 */
		public static function getDefaults(): array {
			return [
				'host'           => '127.0.0.1',
				'port'           => 5672,
				'user'           => 'guest',
				'password'       => 'guest',
				'vhost'          => '/',
				'queue_name'     => 'default',
				'exchange_name'  => '',
				'prefetch_count' => 1,
				'queue_max_jobs' => 500,
			];
		}
		
		/**
		 * Resolve and type-normalize a raw config array against defaults.
		 * Used by both the service provider and the consumer so type coercion
		 * lives in one place.
		 * @param array<string, mixed> $raw
		 * @return RabbitMQConfig
		 */
		public static function resolveConfig(array $raw): array {
			$defaults = self::getDefaults();
			
			return [
				'host'           => is_string($raw['host'] ?? null) ? $raw['host'] : $defaults['host'],
				'port'           => is_int($raw['port'] ?? null) ? $raw['port'] : $defaults['port'],
				'user'           => is_string($raw['user'] ?? null) ? $raw['user'] : $defaults['user'],
				'password'       => is_string($raw['password'] ?? null) ? $raw['password'] : $defaults['password'],
				'vhost'          => is_string($raw['vhost'] ?? null) ? $raw['vhost'] : $defaults['vhost'],
				'queue_name'     => is_string($raw['queue_name'] ?? null) ? $raw['queue_name'] : $defaults['queue_name'],
				'exchange_name'  => is_string($raw['exchange_name'] ?? null) ? $raw['exchange_name'] : $defaults['exchange_name'],
				'prefetch_count' => is_int($raw['prefetch_count'] ?? null) ? $raw['prefetch_count'] : $defaults['prefetch_count'],
				'queue_max_jobs' => is_int($raw['queue_max_jobs'] ?? null) ? $raw['queue_max_jobs'] : $defaults['queue_max_jobs'],
			];
		}
		
		/**
		 * Merge injected config over defaults
		 * @return RabbitMQConfig
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
				return $metadata['context'] === 'rabbitmq';
			}
			
			// Priority 2: Check config for preferred queue driver
			if ($this->hasConfigValue('queue_driver')) {
				return $this->getConfigValue('queue_driver') === 'rabbitmq';
			}
			
			return true;
		}
		
		/**
		 * Creates and returns a configured RabbitMQQueue instance
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
			
			$connection = new AMQPStreamConnection(
				$config['host'],
				$config['port'],
				$config['user'],
				$config['password'],
				$config['vhost']
			);
			
			return self::$instance = new RabbitMQQueue(
				$connection,
				$config['queue_name'],
				$config['exchange_name'],
				$config['prefetch_count']
			);
		}
	}