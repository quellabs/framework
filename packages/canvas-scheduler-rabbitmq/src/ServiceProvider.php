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
	 *     prefetch_count: int
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
		 * Returns default RabbitMQ connection settings
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
			];
		}
		
		/**
		 * Merge user config over defaults
		 * @return RabbitMQConfig
		 */
		private function mergeConfig(): array {
			$defaults = self::getDefaults();
			$config = $this->getConfig();
			
			return [
				'host'           => $this->normalizeString($config['host'] ?? null, $defaults['host']),
				'port'           => $this->normalizeInt($config['port'] ?? null, $defaults['port']),
				'user'           => $this->normalizeString($config['user'] ?? null, $defaults['user']),
				'password'       => $this->normalizeString($config['password'] ?? null, $defaults['password']),
				'vhost'          => $this->normalizeString($config['vhost'] ?? null, $defaults['vhost']),
				'queue_name'     => $this->normalizeString($config['queue_name'] ?? null, $defaults['queue_name']),
				'exchange_name'  => $this->normalizeString($config['exchange_name'] ?? null, $defaults['exchange_name']),
				'prefetch_count' => $this->normalizeInt($config['prefetch_count'] ?? null, $defaults['prefetch_count']),
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
				return $metadata['provider'] === 'rabbitmq';
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
			
			// Set prefetch so each worker only holds one unacknowledged message at a time,
			// giving fair work distribution when multiple workers share the same queue
			$channel = $connection->channel();
			$channel->basic_qos(0, $config['prefetch_count'], false);
			$channel->close();
			
			return self::$instance = new RabbitMQQueue(
				$connection,
				$config['queue_name'],
				$config['exchange_name']
			);
		}
	}