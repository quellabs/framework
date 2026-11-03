<?php

	namespace Quellabs\CanvasEloquent;

	use Illuminate\Database\Eloquent\Model;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\CanvasEloquent\Annotations\ResolveModel;
	use Quellabs\CanvasEloquent\Exceptions\ModelBindingException;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\Contracts\DependencyInjection\ResolutionNotFoundException;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;

	/**
	 * Resolves controller action parameters typed as a concrete Eloquent model to the stored model
	 * whose primary key matches the route value — the same DI path canvas-objectquel uses for entities.
	 * Relies on ServiceProvider having booted the Capsule, since Model::find() resolves its connection
	 * through Eloquent's own static connection resolver rather than through this provider.
	 *
	 * Implements ServiceProviderInterface directly rather than extending the shared
	 * dependency-injection ServiceProvider base class: that base class's createInstance() returns a
	 * non-nullable object, while model binding must legitimately return null (nothing to bind to), and
	 * PHP's covariance rules forbid a subclass from widening an already-narrowed return type back up.
	 */
	class ModelBindingServiceProvider implements ServiceProviderInterface {

		/**
		 * Dependency injection container, used to lazily resolve the AnnotationReader
		 * @var ContainerInterface
		 */
		private ContainerInterface $container;

		/**
		 * Cached AnnotationReader instance, resolved from the container on first use
		 * @var AnnotationReader|null
		 */
		private ?AnnotationReader $annotationReader = null;

		/**
		 * @var array<string, mixed>
		 */
		private array $config = [];

		/**
		 * @param ContainerInterface $container
		 */
		public function __construct(ContainerInterface $container) {
			$this->container = $container;
		}

		/**
		 * Returns provider metadata. No metadata required.
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
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = $config;
		}

		/**
		 * Determines if this provider can create instances of the given class
		 * @param string $className The fully qualified class name to check
		 * @param array<string, mixed> $metadata Metadata for filtering
		 * @return bool True if the class is a concrete subclass of Illuminate's Model
		 */
		public function supports(string $className, array $metadata): bool {
			return is_subclass_of($className, Model::class);
		}

		/**
		 * Resolves a controller method parameter typed as a Model subclass to a model instance.
		 *
		 * Resolution order:
		 * 1. Read the name of the parameter currently being autowired from the method context
		 * 2. Build a map of parameter name -> routeParam from @ResolveModel annotations on the method
		 * 3. Look up that map by the parameter name found in step 1
		 * 4. If no annotation targets this parameter, use the parameter's own name as the
		 *    route parameter (Laravel/Symfony-style implicit binding)
		 * 5. Fetch the model via its own find(), throwing ResolutionNotFoundException in
		 *    place of its null when no row exists
		 *
		 * A missing route key or an unsupported value shape (e.g. a composite-key array — not
		 * supported by this provider) is a binding/configuration mistake and throws
		 * ModelBindingException; it is never silently treated as "no row found". Only a valid
		 * lookup that genuinely finds nothing throws ResolutionNotFoundException — plain null is
		 * reserved for "nothing to bind to" (no method context, or no parameter being resolved).
		 * @param string $className Fully qualified model class name to resolve
		 * @param array<int|string, mixed> $dependencies Already-resolved dependencies (unused for models)
		 * @param array<string, mixed> $metadata Provider metadata
		 * @param MethodContextInterface|null $methodContext Context of the controller method being invoked
		 * @return object|null The resolved model, or null if nothing to bind to
		 * @throws AnnotationReaderException If annotation parsing fails
		 * @throws ModelBindingException If the route has no value for the binding, or an unsupported shape
		 * @throws ResolutionNotFoundException If no row matches the resolved primary key
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): ?object {
			// No method context means we're not in a request cycle — nothing to resolve
			if ($methodContext === null) {
				return null;
			}

			// The autowirer sets this to the exact parameter currently being resolved
			// before calling into this provider — no need to guess it via reflection
			$parameterName = $methodContext->getParameterName();

			// Null here means the context isn't in the middle of resolving a parameter
			// (e.g. used outside the autowiring loop) — nothing to bind to
			if ($parameterName === null) {
				return null;
			}

			$annotationReader = $this->getAnnotationReader();
			$arguments = $methodContext->getArguments();

			// Build a map of parameter name -> routeParam from @ResolveModel annotations
			$annotations = $annotationReader->getMethodAnnotations(
				$methodContext->getClassName(),
				$methodContext->getMethodName(),
				ResolveModel::class
			);

			$routeParamByParameter = [];
			foreach ($annotations as $annotation) {
				if (!$annotation instanceof ResolveModel) {
					continue;
				}

				$routeParamByParameter[$annotation->getParameter()] = $annotation->getRouteParam();
			}

			// Use the routeParam from the annotation targeting this parameter, if any;
			// otherwise bind implicitly by the parameter's own name
			$routeParam = $routeParamByParameter[$parameterName] ?? $parameterName;

			if (!array_key_exists($routeParam, $arguments)) {
				throw new ModelBindingException(
					$methodContext->getClassName(),
					$methodContext->getMethodName(),
					$parameterName,
					$routeParam,
					$className,
					"the route has no value for key '{$routeParam}'"
				);
			}

			// Only scalar primary keys are supported; mapping a route to a composite
			// key needs its own, explicit design.
			$value = $arguments[$routeParam];

			if (!is_string($value) && !is_int($value)) {
				throw new ModelBindingException(
					$methodContext->getClassName(),
					$methodContext->getMethodName(),
					$parameterName,
					$routeParam,
					$className,
					'route value is not a string or integer, and composite-key binding is not supported'
				);
			}

			/**
			 * @var class-string<Model> $className
			 */
			$model = $className::query()->find($value);

			if ($model === null) {
				throw new ResolutionNotFoundException(
					"No {$className} found for '{$routeParam}' = '{$value}'"
				);
			}

			return $model;
		}

		/**
		 * Returns the AnnotationReader, resolving it from the container on first use.
		 * Lazy resolution is required because it is not available at provider
		 * construction time — it is registered after the container boots.
		 * @return AnnotationReader
		 */
		private function getAnnotationReader(): AnnotationReader {
			if ($this->annotationReader === null) {
				// Fetch the annotation reader
				$annotationReader = $this->container->get(AnnotationReader::class);

				// container->get() returns T|null; assert presence so PHPStan
				// narrows the type and we fail fast if wiring is broken
				if (!$annotationReader instanceof AnnotationReader) {
					throw new \RuntimeException('AnnotationReader could not be resolved from the container');
				}

				$this->annotationReader = $annotationReader;
			}

			return $this->annotationReader;
		}
	}
