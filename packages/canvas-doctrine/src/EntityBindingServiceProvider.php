<?php

	namespace Quellabs\CanvasDoctrine;

	use Doctrine\ORM\EntityManagerInterface;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\CanvasDoctrine\Annotations\ResolveEntity;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\Contracts\DependencyInjection\ResolutionNotFoundException;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;

	/**
	 * Resolves mapped controller entities from route values.
	 */
	class EntityBindingServiceProvider implements ServiceProviderInterface {
		private ContainerInterface $container;
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
		 * @param string $className
		 * @param array<int|string, mixed> $dependencies
		 * @param array<string, mixed> $metadata
		 * @param MethodContextInterface|null $methodContext
		 * @return object|null
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): ?object {
			if ($methodContext === null || $methodContext->getParameterName() === null) {
				return null;
			}
			$parameter = $methodContext->getParameterName();
			$reader = $this->container->get(AnnotationReader::class);
			$manager = $this->container->get(EntityManagerInterface::class);
			if (!$reader instanceof AnnotationReader || !$manager instanceof EntityManagerInterface) {
				throw new \RuntimeException('Doctrine binding requires an AnnotationReader and EntityManager');
			}
			$routeKey = $parameter;
			foreach ($reader->getMethodAnnotations($methodContext->getClassName(), $methodContext->getMethodName(), ResolveEntity::class) as $annotation) {
				if ($annotation instanceof ResolveEntity && $annotation->getParameter() === $parameter) {
					$routeKey = $annotation->getRouteParam();
				}
			}
			$arguments = $methodContext->getArguments();
			if (!array_key_exists($routeKey, $arguments) || (!is_string($arguments[$routeKey]) && !is_int($arguments[$routeKey]))) {
				throw new \InvalidArgumentException("Route key '{$routeKey}' must contain a scalar entity identifier");
			}
			$entity = $manager->find($className, $arguments[$routeKey]);
			if ($entity === null) {
				throw new ResolutionNotFoundException("No {$className} found for '{$routeKey}'");
			}
			return $entity;
		}
	}
