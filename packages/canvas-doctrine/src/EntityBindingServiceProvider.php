<?php

	namespace Quellabs\CanvasDoctrine;

	use Doctrine\ORM\EntityManagerInterface;
	use Doctrine\ORM\Exception\ORMException;
	use Doctrine\ORM\OptimisticLockException;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\CanvasDoctrine\Annotations\ResolveEntity;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\Contracts\DependencyInjection\ResolutionNotFoundException;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;

	/**
	 * Resolves mapped controller entities from route values.
	 * Matches by entity_namespace only; do not register this alongside
	 * canvas-objectquel with an overlapping namespace.
	 */
	class EntityBindingServiceProvider implements ServiceProviderInterface {
		
		/** @var ContainerInterface AnnotationReader */
		private ContainerInterface $container;

		/** @var array<string, mixed> */
		private array $config = [];

		/**
		 * Stores the container for lazy service resolution.
		 * @param ContainerInterface $container
		 * @return void
		 */
		public function __construct(ContainerInterface $container) {
			$this->container = $container;
		}

		/**
		 * Returns provider metadata.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [];
		}

		/**
		 * Returns provider configuration.
		 * @return array<string, mixed>
		 */
		public function getConfig(): array {
			return $this->config;
		}

		/**
		 * Sets provider configuration.
		 * @param array<string, mixed> $config
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = $config;
		}

		/**
		 * Checks the configured entity namespace without resolving services.
		 * @param string $className
		 * @param array<string, mixed> $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			$namespace = $this->config['entity_namespace'] ?? 'App\\Entities';
			return is_string($namespace) && str_starts_with($className, rtrim($namespace, '\\') . '\\');
		}
		
		/**
		 * Finds an entity using its scalar route identifier.
		 * @param class-string $className
		 * @param array<int|string, mixed> $dependencies
		 * @param array<string, mixed> $metadata
		 * @param MethodContextInterface|null $methodContext
		 * @return object|null
		 * @throws AnnotationReaderException
		 * @throws ORMException
		 * @throws OptimisticLockException
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): ?object {
			if ($methodContext === null || $methodContext->getParameterName() === null) {
				return null;
			}

			$parameter = $methodContext->getParameterName();
			$reader = $this->resolveAnnotationReader();
			$manager = $this->resolveEntityManager();
			$routeKey = $this->resolveRouteKey($methodContext, $parameter, $reader);
			$identifier = $this->resolveEntityIdentifier($methodContext->getArguments(), $routeKey);
			return $this->findEntity($manager, $className, $identifier, $routeKey);
		}

		/**
		 * Resolves the annotation reader from the container.
		 * @return AnnotationReader
		 * @throws \RuntimeException
		 */
		private function resolveAnnotationReader(): AnnotationReader {
			$reader = $this->container->get(AnnotationReader::class);

			if (!$reader instanceof AnnotationReader) {
				throw new \RuntimeException('Doctrine binding requires an AnnotationReader');
			}

			return $reader;
		}

		/**
		 * Resolves the entity manager from the container.
		 * @return EntityManagerInterface
		 * @throws \RuntimeException
		 */
		private function resolveEntityManager(): EntityManagerInterface {
			$manager = $this->container->get(EntityManagerInterface::class);

			if (!$manager instanceof EntityManagerInterface) {
				throw new \RuntimeException('Doctrine binding requires an EntityManager');
			}

			return $manager;
		}
		
		/**
		 * Returns the route key bound to the parameter via ResolveEntity, or the parameter name itself.
		 * @param MethodContextInterface $methodContext
		 * @param string $parameter
		 * @param AnnotationReader $reader
		 * @return string
		 * @throws AnnotationReaderException
		 */
		private function resolveRouteKey(MethodContextInterface $methodContext, string $parameter, AnnotationReader $reader): string {
			$annotations = $reader->getMethodAnnotations($methodContext->getClassName(), $methodContext->getMethodName(), ResolveEntity::class);

			foreach ($annotations as $annotation) {
				if ($annotation instanceof ResolveEntity && $annotation->getParameter() === $parameter) {
					return $annotation->getRouteParam();
				}
			}

			return $parameter;
		}

		/**
		 * Validates and returns the scalar entity identifier found under the route key.
		 * @param array<string, mixed> $arguments
		 * @param string $routeKey
		 * @return string|int
		 * @throws \InvalidArgumentException
		 */
		private function resolveEntityIdentifier(array $arguments, string $routeKey): string|int {
			if (!array_key_exists($routeKey, $arguments) || (!is_string($arguments[$routeKey]) && !is_int($arguments[$routeKey]))) {
				throw new \InvalidArgumentException("Route key '{$routeKey}' must contain a scalar entity identifier");
			}

			return $arguments[$routeKey];
		}
		
		/**
		 * Finds the entity by its identifier, or throws when no row matches.
		 * @param EntityManagerInterface $manager
		 * @param class-string $className
		 * @param string|int $identifier
		 * @param string $routeKey
		 * @return object
		 * @throws ORMException
		 * @throws OptimisticLockException
		 */
		private function findEntity(EntityManagerInterface $manager, string $className, string|int $identifier, string $routeKey): object {
			$entity = $manager->find($className, $identifier);

			if ($entity === null) {
				throw new ResolutionNotFoundException("No {$className} found for '{$routeKey}'");
			}

			return $entity;
		}
	}
