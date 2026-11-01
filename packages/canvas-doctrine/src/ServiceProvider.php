<?php

	namespace Quellabs\CanvasDoctrine;

	use Doctrine\DBAL\Connection;
	use Doctrine\DBAL\DriverManager;
	use Doctrine\ORM\EntityManager;
	use Doctrine\ORM\EntityManagerInterface;
	use Doctrine\ORM\ORMSetup;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;
	use Quellabs\Support\ComposerUtils;

	/**
	 * Provides one configured Doctrine EntityManager and its connection.
	 */
	class ServiceProvider extends BaseServiceProvider {
		private ?EntityManager $entityManager = null;

		/**
		 * Checks whether this provider owns the requested service.
		 * @param string $className
		 * @param array<string, mixed> $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			return in_array($className, [EntityManager::class, EntityManagerInterface::class, Connection::class], true);
		}

		/**
		 * Returns the shared manager or its connection.
		 * @param string $className
		 * @param array<int|string, mixed> $dependencies
		 * @param array<string, mixed> $metadata
		 * @param MethodContextInterface|null $methodContext
		 * @return object
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			$manager = $this->getEntityManager();
			return $className === Connection::class ? $manager->getConnection() : $manager;
		}

		/**
		 * Builds the single manager from the published configuration.
		 * @return EntityManager
		 */
		private function getEntityManager(): EntityManager {
			if ($this->entityManager !== null) {
				return $this->entityManager;
			}

			$config = $this->getConfig();
			$paths = $config['entity_paths'] ?? [ComposerUtils::getProjectRoot() . '/src/Entities'];
			if (!is_array($paths) || $paths === [] || array_filter($paths, fn ($path) => !is_string($path) || $path === '') !== []) {
				throw new \InvalidArgumentException('doctrine.entity_paths must be a non-empty array of paths');
			}
			$connection = $config['connection'] ?? [];
			if (!is_array($connection) || isset($connection['connections']) || isset($config['connections'])) {
				throw new \InvalidArgumentException('Doctrine supports one connection; configure doctrine.connection directly');
			}
			$defaults = ['driver' => 'pdo_mysql', 'host' => 'localhost', 'port' => 3306, 'dbname' => '', 'user' => '', 'password' => '', 'charset' => 'utf8mb4'];
			$ormConfig = ORMSetup::createAttributeMetadataConfiguration($paths, (bool)($config['development_mode'] ?? false));
			$this->entityManager = new EntityManager(DriverManager::getConnection(array_merge($defaults, $connection)), $ormConfig);
			return $this->entityManager;
		}
	}
