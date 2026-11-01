<?php
	
	namespace Quellabs\CanvasDoctrine;
	
	use Doctrine\DBAL\Connection;
	use Doctrine\ORM\EntityManager;
	use Doctrine\ORM\EntityManagerInterface;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as BaseServiceProvider;

	/**
	 * Provides one configured Doctrine EntityManager and its connection.
	 */
	class ServiceProvider extends BaseServiceProvider {
		/** @var EntityManager|null Doctrine's EntityManager */
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
		 * @throws \InvalidArgumentException
		 * @throws \Doctrine\DBAL\Exception
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			$manager = $this->getEntityManager();
			return $className === Connection::class ? $manager->getConnection() : $manager;
		}
		
		/**
		 * Builds the single manager from the published configuration.
		 * @return EntityManager
		 * @throws \InvalidArgumentException
		 * @throws \Doctrine\DBAL\Exception
		 */
		private function getEntityManager(): EntityManager {
			if ($this->entityManager !== null) {
				return $this->entityManager;
			}

			return $this->entityManager = EntityManagerFactory::create($this->getConfig());
		}
	}
