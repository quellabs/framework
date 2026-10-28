<?php
	
	namespace Quellabs\CanvasObjectQuel;
	
	use Quellabs\Canvas\Kernel;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\CanvasObjectQuel\Annotations\ResolveEntity;
	use Quellabs\CanvasObjectQuel\Exceptions\EntityBindingException;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\Contracts\DependencyInjection\NotFound;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;

	/**
	 * ServiceProvider bridges Canvas's DI system and ObjectQuel's EntityManager,
	 * enabling automatic entity resolution for controller method parameters.
	 *
	 * When Canvas needs to resolve a controller parameter typed as an entity class,
	 * this provider intercepts the resolution, reads any @ResolveEntity annotations
	 * on the method — matched to the parameter by name, not by entity class — to
	 * determine which route parameter holds the primary key, and fetches the entity
	 * via the EntityManager.
	 *
	 * Convention: if no @ResolveEntity annotation targets the parameter, the route
	 * parameter with the same name as the controller parameter is used (e.g. route
	 * "/posts/{slug}" binds to "function show(PostEntity $slug)" with no annotation
	 * needed). The annotation is only required when the route parameter name differs
	 * from the controller parameter's name.
	 *
	 * Returns NotFound::instance() (not plain null) when the lookup finds no row,
	 * so CanvasAutowirer can tell "no matching record" apart from a null that an
	 * ordinary service provider (cache, logger, etc.) might return for its own,
	 * unrelated reason — only the former is eligible to become a required-
	 * parameter 404.
	 */
	class ServiceProvider implements ServiceProviderInterface {
		
		private ContainerInterface $container;
		private ?Kernel $kernel;
		private ?EntityManager $entityManager;

		/** @var array<string, mixed> */
		private array $config;

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
		 * 1. Read the name of the parameter currently being autowired from the method context
		 * 2. Build a map of parameter name -> routeParam from @ResolveEntity annotations on the method
		 * 3. Look up that map by the parameter name found in step 1
		 * 4. If no annotation targets this parameter, use the parameter's own name as the
		 *    route parameter (Laravel/Symfony-style implicit binding)
		 * 5. Fetch the entity via EntityManager::find(), returning NotFound::instance()
		 *    in place of its null when no row exists
		 *
		 * A missing route key or an unsupported value shape (e.g. a composite-key array —
		 * not supported by this provider) is a binding/configuration mistake and throws
		 * EntityBindingException; it is never silently treated as "no row found". Only a
		 * valid lookup that genuinely finds nothing returns NotFound::instance() — plain
		 * null is reserved for "nothing to bind to" (no method context, or no parameter
		 * being resolved), which is a different situation entirely.
		 *
		 * @param string $className Fully qualified entity class name to resolve
		 * @param array<string, mixed> $dependencies Already-resolved dependencies (unused for entities)
		 * @param array<string, mixed> $metadata Provider metadata
		 * @param MethodContextInterface|null $methodContext Context of the controller method being invoked
		 * @return object|NotFound|null The resolved entity, NotFound if no matching record exists, or null if nothing to bind to
		 * @throws AnnotationReaderException If annotation parsing fails
		 * @throws EntityBindingException If the route has no value for the binding, or an unsupported shape
		 * @throws EntityResolutionException If the entity cannot be resolved
		 * @throws QuelException If the ObjectQuel query fails
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): ?object {
			// No method context means we're not in a request cycle — nothing to resolve
			if ($methodContext === null) {
				return null;
			}

			$annotationReader = $this->getKernel()->getAnnotationsReader();
			$arguments = $methodContext->getArguments();

			// The autowirer sets this to the exact parameter currently being resolved
			// before calling into this provider — no need to guess it via reflection
			$parameterName = $methodContext->getParameterName();

			// Null here means the context isn't in the middle of resolving a parameter
			// (e.g. used outside the autowiring loop) — nothing to bind to
			if ($parameterName === null) {
				return null;
			}

			// Build a map of parameter name -> routeParam from @ResolveEntity annotations
			$annotations = $annotationReader->getMethodAnnotations(
				$methodContext->getClassName(),
				$methodContext->getMethodName(),
				ResolveEntity::class
			);

			$routeParamByParameter = [];

			foreach ($annotations as $annotation) {
				// AnnotationReader returns object[]; narrow to ResolveEntity before use
				if (!$annotation instanceof ResolveEntity) {
					continue;
				}

				$routeParamByParameter[$annotation->getParameter()] = $annotation->getRouteParam();
			}

			// Use the routeParam from the annotation targeting this parameter, if any;
			// otherwise bind implicitly by the parameter's own name
			$routeParam = $routeParamByParameter[$parameterName] ?? $parameterName;

			if (!array_key_exists($routeParam, $arguments)) {
				throw new EntityBindingException(
					$methodContext->getClassName(),
					$methodContext->getMethodName(),
					$parameterName,
					$routeParam,
					$className,
					"the route has no value for key '{$routeParam}'"
				);
			}

			$value = $arguments[$routeParam];

			// EntityManager::find() also accepts composite (array) primary keys, but
			// mapping a route to a composite key needs its own, explicit design — this
			// provider deliberately supports only scalar keys for now.
			if (!is_string($value) && !is_int($value)) {
				throw new EntityBindingException(
					$methodContext->getClassName(),
					$methodContext->getMethodName(),
					$parameterName,
					$routeParam,
					$className,
					'route value is not a string or integer, and composite-key binding is not supported'
				);
			}

			/** @var class-string<object> $className */
			$entity = $this->getEntityManager()->find($className, $value);

			return $entity ?? NotFound::instance();
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