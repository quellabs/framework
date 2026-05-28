<?php
	
	namespace Quellabs\CanvasObjectQuel;
	
	use Quellabs\Canvas\Kernel;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\CanvasObjectQuel\Annotations\ResolveEntity;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;
	
	/**
	 * ServiceProvider bridges Canvas's DI system and ObjectQuel's EntityManager,
	 * enabling automatic entity resolution for controller method parameters.
	 *
	 * When Canvas needs to resolve a controller parameter typed as an entity class,
	 * this provider intercepts the resolution, reads any @ResolveEntity annotations
	 * on the method to determine which route parameter holds the primary key, and
	 * fetches the entity via the EntityManager.
	 *
	 * Convention: if no @ResolveEntity annotation is present, {id} is used as the
	 * route parameter name. The annotation is only required when the route parameter
	 * name differs from "id" or when multiple entity parameters need to be disambiguated.
	 */
	class ServiceProvider implements ServiceProviderInterface {
		
		private ContainerInterface $container;
		private ?Kernel $kernel;
		private ?EntityManager $entityManager;
		
		/** @var array<string, mixed> */
		private array $config;
		
		/**
		 * Cache of resolved parameter names, keyed by "contextClass::method::entityClass".
		 * Avoids repeated reflection on the same controller method across multiple
		 * entity resolutions within the same request.
		 * @var array<string, string|null>
		 */
		private array $parameterCache = [];
		
		/**
		 * @param ContainerInterface $container
		 */
		public function __construct(ContainerInterface $container) {
			$this->container = $container;
			$this->kernel = null;
			$this->entityManager = null;
			$this->config = [];
		}
		
		/**
		 * Returns provider metadata. No metadata required for entity resolution.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [];
		}
		
		/**
		 * @return array<string, mixed>
		 */
		public function getConfig(): array {
			return $this->config;
		}
		
		/**
		 * @param array<string, mixed> $config
		 */
		public function setConfig(array $config): void {
			$this->config = $config;
		}
		
		/**
		 * Returns true if the given class lives under the configured entity namespace.
		 * Intentionally does not touch the EntityManager or container — supports() is
		 * called for every parameter on every request, including during boot before
		 * EntityManager is registered, so it must be safe to call at any time.
		 * @param string $className Fully qualified class name of the parameter type
		 * @param array<string, mixed> $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			$entityNamespace = $this->config['entity_namespace'] ?? 'App\\Entities';
			
			// Config values are mixed; fall back to the default if misconfigured
			if (!is_string($entityNamespace)) {
				$entityNamespace = 'App\\Entities';
			}
			
			return str_starts_with($className, $entityNamespace . '\\');
		}
		
		/**
		 * Resolves a controller method parameter to an entity instance.
		 *
		 * Resolution order:
		 * 1. Reflect on the method signature to find which parameter corresponds to $className
		 * 2. Look for a @ResolveEntity annotation whose target matches that parameter name
		 * 3. If found, use the annotation's routeParam to read the primary key from route arguments
		 * 4. If not found, fall back to the conventional {id} route parameter
		 * 5. Fetch and return the entity via EntityManager::find(), or null if not found
		 *
		 * @param string $className Fully qualified entity class name to resolve
		 * @param array<string, mixed> $dependencies Already-resolved dependencies (unused for entities)
		 * @param array<string, mixed> $metadata Provider metadata
		 * @param MethodContextInterface|null $methodContext Context of the controller method being invoked
		 * @return object|null The resolved entity, or null if no matching record exists
		 * @throws AnnotationReaderException If annotation parsing fails
		 * @throws EntityResolutionException If the entity cannot be resolved
		 * @throws QuelException If the ObjectQuel query fails
		 * @throws \ReflectionException If the controller method cannot be reflected
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): ?object {
			// No method context means we're not in a request cycle — nothing to resolve
			if ($methodContext === null) {
				return null;
			}
			
			$annotationReader = $this->getKernel()->getAnnotationsReader();
			$arguments = $methodContext->getArguments();
			
			// Determine which parameter name on this method corresponds to $className,
			// so we can match it against @ResolveEntity annotations by target name
			$parameterName = $this->getParameterNameForClass(
				$className,
				$methodContext->getClassName(),
				$methodContext->getMethodName()
			);
			
			// Find a @ResolveEntity annotation whose target matches the parameter name
			$annotations = $annotationReader->getMethodAnnotations(
				$methodContext->getClassName(),
				$methodContext->getMethodName(),
				ResolveEntity::class
			);
			
			foreach ($annotations as $annotation) {
				// AnnotationReader returns object[]; narrow to ResolveEntity before use
				if (!$annotation instanceof ResolveEntity) {
					continue;
				}
				
				// Wrong class type
				if ($annotation->getEntityClass() !== $className) {
					continue;
				}
				
				// Use the route parameter specified in the annotation
				$value = $arguments[$annotation->getRouteParam()] ?? null;
				
				// A null primary key would cause EntityManager::find() to throw — return
				// null instead so the controller receives null and can handle it gracefully
				if (!is_string($value) && !is_int($value)) {
					return null;
				}
				
				/** @var class-string<object> $className */
				return $this->getEntityManager()->find($className, $value);
			}
			
			// Convention fallback: no annotation matched, assume the primary key
			// is carried by the {id} route parameter
			$value = $arguments['id'] ?? null;
			
			if (!is_string($value) && !is_int($value)) {
				return null;
			}
			
			/** @var class-string<object> $className */
			return $this->getEntityManager()->find($className, $value);
		}
		
		/**
		 * Finds the name of the method parameter typed as $className, using reflection.
		 *
		 * Results are cached per class+method+entityClass combination to avoid
		 * repeated reflection on the same controller method within a request.
		 *
		 * @param string $className Fully qualified entity class name to look for
		 * @param string $contextClass Fully qualified controller class name
		 * @param string $contextMethod Controller method name
		 * @return string|null The parameter name, or null if no matching parameter is found
		 * @throws \ReflectionException If the method does not exist
		 */
		private function getParameterNameForClass(string $className, string $contextClass, string $contextMethod): ?string {
			$cacheKey = "{$contextClass}::{$contextMethod}::{$className}";
			
			// Return cached result if this combination has been reflected before
			if (array_key_exists($cacheKey, $this->parameterCache)) {
				return $this->parameterCache[$cacheKey];
			}
			
			$reflection = new \ReflectionMethod($contextClass, $contextMethod);
			
			foreach ($reflection->getParameters() as $parameter) {
				$type = $parameter->getType();
				
				// Skip untyped parameters and union/intersection types — only named
				// types can match an entity class name
				if (!$type instanceof \ReflectionNamedType) {
					continue;
				}
				
				if ($type->getName() === $className) {
					return $this->parameterCache[$cacheKey] = $parameter->getName();
				}
			}
			
			// No parameter found for this class; cache null to avoid re-reflecting
			return $this->parameterCache[$cacheKey] = null;
		}
		
		/**
		 * Returns the EntityManager, resolving it from the container on first use.
		 * Lazy resolution is required because EntityManager is not available at
		 * provider construction time — it is registered after the container boots.
		 * @return EntityManager
		 */
		private function getEntityManager(): EntityManager {
			if ($this->entityManager === null) {
				$entityManager = $this->container->get(EntityManager::class);
				
				// container->get() returns T|null; assert presence so PHPStan
				// narrows the type and we fail fast if wiring is broken
				if (!$entityManager instanceof EntityManager) {
					throw new \RuntimeException('EntityManager could not be resolved from the container');
				}
				
				$this->entityManager = $entityManager;
			}
			
			return $this->entityManager;
		}
		
		/**
		 * Returns the Kernel, resolving it from the container on first use.
		 * Lazy resolution is required because Kernel is not available at
		 * provider construction time — it is registered after the container boots.
		 * @return Kernel
		 */
		private function getKernel(): Kernel {
			if ($this->kernel === null) {
				$kernel = $this->container->get(Kernel::class);
				
				// container->get() returns T|null; assert presence so PHPStan
				// narrows the type and we fail fast if wiring is broken
				if (!$kernel instanceof Kernel) {
					throw new \RuntimeException('Kernel could not be resolved from the container');
				}
				
				$this->kernel = $kernel;
			}
			
			return $this->kernel;
		}
	}