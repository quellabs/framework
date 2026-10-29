<?php
	
	namespace Quellabs\DependencyInjection\Autowiring;
	
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\Contracts\DependencyInjection\ResolutionNotFoundException;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\DependencyInjection\Exception\UnresolvableParameterException;

	/**
	 * class Autowirer
	 *
	 * @phpstan-type ParameterMeta array{
	 *     name: string,
	 *     types?: array<int, string>,
	 *     typeGroups?: array<int, array<int, string>>,
	 *     nullable: bool,
	 *     default_value?: mixed,
	 *     context?: array<string, mixed>
	 * }
	 */
	class Autowirer {
		
		/**
		 * Sentinel returned by resolveType() to signal "no provider handled this type",
		 * distinct from null which a provider may return intentionally (e.g. entity not found).
		 */
		private static ?object $unresolvedSentinel = null;
		
		/**
		 * Returns the shared sentinel instance, creating it on first call.
		 * @return object
		 */
		private static function unresolved(): object {
			return self::$unresolvedSentinel ??= new class {};
		}
		
		/**
		 * @var ContainerInterface
		 */
		protected ContainerInterface $container;
		
		/**
		 * Cache of reflected parameter metadata keyed by "ClassName::methodName".
		 * Reflection results are immutable at runtime, so this is safe to cache
		 * for the lifetime of the autowirer instance.
		 * @var array<string, array<int, ParameterMeta>>
		 */
		private array $parameterCache = [];
		
		/**
		 * Built-in PHP types that cannot be resolved from container
		 */
		private const array BUILTIN_TYPES = [
			// Basic scalar types
			'string', 'int', 'float', 'bool',
			
			// Legacy aliases for scalar types
			'integer', 'boolean', 'double',
			
			// Compound types
			'array', 'object', 'callable', 'iterable',
			
			// Special types
			'mixed', 'null', 'false', 'true',
			
			// Resource (rarely used as a parameter type)
			'resource',
		];
		
		/**
		 * Autowirer constructor
		 * @param ContainerInterface $container
		 */
		public function __construct(ContainerInterface $container) {
			$this->container = $container;
		}
		
		/**
		 * Resolves and returns arguments for a specific method.
		 * @param object|class-string $class The class instance or class name
		 * @param string $methodName The name of the method to resolve arguments for
		 * @param array<string, mixed> $parameters Optional array of parameters to use for resolution
		 * @param MethodContextInterface|null $methodContext
		 * @return array<int, mixed> The resolved arguments array ready for method invocation
		 */
		public function getMethodArguments(
			object|string $class,
			string $methodName,
			array $parameters = [],
			?MethodContextInterface $methodContext = null
		): array {
			// Fetch the name of the class - handle both object instances and class name strings
			$className = is_object($class) ? get_class($class) : $class;
			
			// Throw when the class does not exist
			if (!class_exists($className)) {
				throw new \InvalidArgumentException("Class '{$className}' does not exist");
			}
			
			// Use the provided context if available, otherwise create a fresh one
			$methodContext = $methodContext ?? new MethodContext($className, $methodName);
			
			// Get method parameter metadata (name, types, defaults, etc.)
			// This likely uses reflection to analyze the method signature
			$methodParams = $this->getMethodParameters($className, $methodName);
			
			// Process each method parameter to build the argument array
			$arguments = [];
			$allParameterIndex = null; // Track position of special "all parameter" if found

			try {
				foreach ($methodParams as $index => $param) {
					// Check if this parameter is a special "all parameter" type
					// This might be a parameter that receives all other resolved parameters
					if ($this->isAllParameter($param)) {
						$allParameterIndex = $index;
						$arguments[] = null;
						continue;
					}

					// Set the parameter that we're currently resolving
					$methodContext->setParameterName($param['name']);

					// Resolve the current parameter using the provided parameters and context
					// This handles type conversion, default values, dependency injection, etc.
					$arguments[] = $this->resolveParameter($param, $parameters, $methodContext, $className, $methodName);
				}
			} finally {
				// Reset the parameter that we're currently resolving, even if resolution
				// threw, so a reused context object never retains a stale parameter name.
				$methodContext->setParameterName(null);
			}
			
			// If we found an "all parameter", give it the entire original parameters array
			// This allows access to all input parameters, including those not defined in the method signature
			if ($allParameterIndex !== null) {
				$arguments[$allParameterIndex] = $parameters;
			}
			
			// Return the final array of resolved arguments in correct order for method call
			return $arguments;
		}
		
		/**
		 * Resolves a single method parameter value using multiple resolution strategies.
		 * Tries strategies in priority order until one succeeds or all fail.
		 * @param ParameterMeta $param Parameter metadata (name, types, default_value, etc.)
		 * @param array<string, mixed> $parameters User-provided parameter values
		 * @param MethodContextInterface|null $methodContext Context object for dependency injection
		 * @param string $className Class name for error reporting
		 * @param string $methodName Method name for error reporting
		 * @return mixed The resolved parameter value
		 * @throws AnnotationReaderException
		 */
		protected function resolveParameter(
			array                   $param,
			array                   $parameters,
			?MethodContextInterface $methodContext,
			string                  $className,
			string                  $methodName
		): mixed {
			$paramName = $param['name'];
			$paramTypes = $param['types'] ?? [];
			$paramTypeGroups = $param['typeGroups'] ?? [];

			// Strategy 1: Direct parameter name match. A raw scalar (e.g. a route
			// argument) must not satisfy a class-typed parameter - those are left
			// for Strategy 2 so providers (e.g. entity resolution) get a chance.
			if (isset($parameters[$paramName]) && $this->valueSatisfiesTypes($parameters[$paramName], $paramTypeGroups)) {
				return $parameters[$paramName];
			}

			// Strategy 2: Dependency injection — sentinel means unresolved, null means
			// resolved-to-null, ResolutionNotFoundException means a provider
			// explicitly found no record for this lookup
			try {
				$resolved = $this->resolveParameterFromTypes($paramName, $paramTypeGroups, $methodContext);
			} catch (ResolutionNotFoundException) {
				return $this->rejectNullForRequiredParameter(
					$this->handleNotFound($param, $className, $methodName),
					$param,
					$className,
					$methodName
				);
			}

			if ($resolved !== self::unresolved()) {
				return $this->rejectNullForRequiredParameter($resolved, $param, $className, $methodName);
			}

			// Strategy 3: Default value from method signature
			if (array_key_exists('default_value', $param)) {
				return $param['default_value'];
			}

			// Strategy 4: Empty array for array-typed parameters
			// Prevents a crash in CakePHP Database connection when autowiring the config
			if (in_array("array", $paramTypes, true)) {
				return [];
			}

			throw new UnresolvableParameterException("Cannot autowire parameter '$paramName' for $className::$methodName");
		}

		/**
		 * Called when a provider threw ResolutionNotFoundException while
		 * resolving this parameter's type, rather than returning an instance or
		 * an ordinary null. The base implementation treats it as the generic,
		 * framework-agnostic outcome — inject null — since base Autowirer has
		 * no concept of an HTTP response. The caller still rejects that null if
		 * the parameter turns out to be required (see
		 * rejectNullForRequiredParameter()). A subclass (Canvas, for a required
		 * parameter) can override this to react differently instead, e.g. by
		 * throwing to produce a 404.
		 * @param ParameterMeta $param Parameter metadata (name, types, nullable, etc.)
		 * @param string $className Class name for error reporting
		 * @param string $methodName Method name for error reporting
		 * @return mixed
		 */
		protected function handleNotFound(array $param, string $className, string $methodName): mixed {
			return null;
		}

		/**
		 * Guards a resolved value against silently becoming an opaque PHP
		 * TypeError at the actual method call: null is only a valid answer for
		 * a parameter that is itself nullable. A required (non-nullable)
		 * parameter that a provider (directly, or via handleNotFound()) resolved
		 * to null is a DI failure and is reported as one immediately, with the
		 * parameter and method named, instead of deferred past this point.
		 * @param mixed $value The value Strategy 2 (or handleNotFound()) resolved to
		 * @param ParameterMeta $param Parameter metadata (name, nullable, etc.)
		 * @param string $className Class name for error reporting
		 * @param string $methodName Method name for error reporting
		 * @return mixed $value, unchanged, when it is acceptable
		 * @throws UnresolvableParameterException If the parameter is required but resolved to null
		 */
		protected function rejectNullForRequiredParameter(mixed $value, array $param, string $className, string $methodName): mixed {
			if ($value === null && !($param['nullable'] ?? true)) {
				throw new UnresolvableParameterException(
					"Cannot autowire required parameter '{$param['name']}' for $className::$methodName: resolved to null"
				);
			}

			return $value;
		}

		/**
		 * Determines if a parameter is the magic __all__ parameter.
		 * @param ParameterMeta $param Parameter metadata
		 * @return bool
		 */
		protected function isAllParameter(array $param): bool {
			// Must be named '__all__'
			if ($param['name'] !== '__all__') {
				return false;
			}
			
			// Must have array type hint or no type hint (allowing mixed/flexible typing)
			$types = $param['types'] ?? [];
			
			// Check if variable is of the correct type
			return empty($types) || in_array('array', $types);
		}
		
		/**
		 * Tries each union branch in order. A branch with multiple members is an
		 * intersection: resolved through its first member, then the result is
		 * verified against the rest. ResolutionNotFoundException propagates
		 * uncaught - it means "no record", not "try the next candidate".
		 * @param string $paramName Parameter name for error messages
		 * @param array<int, array<int, string>> $typeGroups Union branches, each an intersection of type names
		 * @param MethodContextInterface|null $methodContext
		 * @return mixed The resolved value (possibly null), or the unresolved sentinel
		 * @throws ResolutionNotFoundException If a provider explicitly found no record
		 */
		protected function resolveParameterFromTypes(string $paramName, array $typeGroups, ?MethodContextInterface $methodContext = null): mixed {
			if (empty($typeGroups)) {
				return self::unresolved();
			}

			foreach ($typeGroups as $group) {
				// Scalars/null can't be resolved via DI
				/** @var list<class-string> $resolvableGroup */
				$resolvableGroup = array_values(array_filter($group, fn($type) => $type !== 'null' && !$this->isBuiltinType($type)));

				if (empty($resolvableGroup)) {
					continue;
				}

				try {
					$resolved = $this->resolveType($resolvableGroup[0], [], $methodContext);
				} catch (UnresolvableParameterException) {
					// This candidate's own construction failed; try the next union branch
					continue;
				}

				// Null is accepted as-is; an instance must satisfy the whole intersection
				if ($resolved === null || $this->valueSatisfiesGroup($resolved, $resolvableGroup)) {
					return $resolved;
				}
			}

			return self::unresolved();
		}
		
		/**
		 * Returns reflected parameter metadata for a method, cached by class+method.
		 * @param class-string $className
		 * @param string $methodName
		 * @return array<int, ParameterMeta>
		 */
		protected function getMethodParameters(string $className, string $methodName): array {
			// Fetch method parameters from cache if possible
			$cacheKey = $className . '::' . $methodName;
			
			if (isset($this->parameterCache[$cacheKey])) {
				return $this->parameterCache[$cacheKey];
			}
			
			$reflectionClass = new \ReflectionClass($className);

			if (empty($methodName) || $methodName === '__construct') {
				$methodReflector = $reflectionClass->getConstructor();

				// No constructor at all is not an error
				if (!$methodReflector) {
					return $this->parameterCache[$cacheKey] = [];
				}
			} else {
				try {
					$methodReflector = $reflectionClass->getMethod($methodName);
				} catch (\ReflectionException $e) {
					// Fail here, not later as an opaque invocation failure
					throw new UnresolvableParameterException(
						"Cannot reflect method '$methodName' on class '$className': {$e->getMessage()}",
						0,
						$e
					);
				}
			}

			// Process each parameter
			$result = [];

			foreach ($methodReflector->getParameters() as $parameter) {
				// Get the name of the parameter
				$param = ['name' => $parameter->getName()];

				// Get the type of the parameter if available
				if ($parameter->hasType()) {
					$param['types'] = $this->extractTypes($parameter->getType());
					$param['typeGroups'] = $this->extractTypeGroups($parameter->getType());
				}

				// allowsNull() is the canonical source of truth for nullability: it
				// correctly covers "?Type", "Type|null", and untyped parameters alike,
				// unlike inferring it from the 'types' list or a coincidental default.
				$param['nullable'] = $parameter->allowsNull();

				// Get the default value if available
				if ($parameter->isDefaultValueAvailable()) {
					$param['default_value'] = $parameter->getDefaultValue();
				} elseif ($parameter->allowsNull()) {
					$param['default_value'] = null;
				}

				// Add the parameter to the parameter list
				$result[] = $param;
			}

			return $this->parameterCache[$cacheKey] = $result;
		}

		/**
		 * Extracts all type hints as a flat list, ignoring union/intersection
		 * structure - only used where that structure doesn't matter.
		 * @param \ReflectionType $type
		 * @return array<int, string>
		 */
		protected function extractTypes(\ReflectionType $type): array {
			// Handle union types (Type1|Type2|Type3)
			if ($type instanceof \ReflectionUnionType) {
				$types = [];

				foreach ($type->getTypes() as $unionType) {
					$types = array_merge($types, $this->extractTypes($unionType));
				}

				return $types;
			}

			// Handle intersection types (Type1&Type2&Type3)
			if ($type instanceof \ReflectionIntersectionType) {
				$types = [];

				foreach ($type->getTypes() as $intersectionType) {
					$types = array_merge($types, $this->extractTypes($intersectionType));
				}

				return $types;
			}

			// Handle named types (regular class/interface names and built-in types)
			if ($type instanceof \ReflectionNamedType) {
				return [$type->getName()];
			}

			// Fallback for unknown type implementations
			return [];
		}

		/**
		 * Extracts type hints as union branches, each branch a list of names
		 * that must ALL be satisfied (an intersection) - preserving the
		 * union/intersection distinction a flat list loses.
		 * @param \ReflectionType $type
		 * @return array<int, array<int, string>>
		 */
		protected function extractTypeGroups(\ReflectionType $type): array {
			if ($type instanceof \ReflectionUnionType) {
				$groups = [];

				foreach ($type->getTypes() as $unionType) {
					$groups = array_merge($groups, $this->extractTypeGroups($unionType));
				}

				return $groups;
			}

			if ($type instanceof \ReflectionIntersectionType) {
				$names = [];

				foreach ($type->getTypes() as $intersectionType) {
					if ($intersectionType instanceof \ReflectionNamedType) {
						$names[] = $intersectionType->getName();
					}
				}

				return [$names];
			}

			if ($type instanceof \ReflectionNamedType) {
				return [[$type->getName()]];
			}

			return [];
		}

		/**
		 * Check if a type is a built-in PHP type that can be used in parameter lists
		 * @param string $type
		 * @return bool
		 */
		protected function isBuiltinType(string $type): bool {
			return in_array($type, self::BUILTIN_TYPES);
		}

		/**
		 * Checks whether a value actually matches a built-in type - instanceof
		 * doesn't apply to scalars/arrays, so each needs its own test.
		 * @param mixed $value
		 * @param string $type One of self::BUILTIN_TYPES
		 * @return bool
		 */
		protected function valueSatisfiesBuiltinType(mixed $value, string $type): bool {
			return match ($type) {
				'string' => is_string($value),
				'int', 'integer' => is_int($value),
				'float', 'double' => is_float($value) || is_int($value),
				'bool', 'boolean' => is_bool($value),
				'array' => is_array($value),
				'object' => is_object($value),
				'callable' => is_callable($value),
				'iterable' => is_iterable($value),
				'false' => $value === false,
				'true' => $value === true,
				'resource' => is_resource($value),
				'mixed' => true,
				'null' => $value === null,
				default => false,
			};
		}

		/**
		 * Checks whether a value satisfies at least one union branch of a
		 * parameter's declared type(s). Untyped (no groups) accepts anything.
		 * @param mixed $value
		 * @param array<int, array<int, string>> $typeGroups
		 * @return bool
		 */
		protected function valueSatisfiesTypes(mixed $value, array $typeGroups): bool {
			if (empty($typeGroups)) {
				return true;
			}

			foreach ($typeGroups as $group) {
				if ($this->valueSatisfiesGroup($value, $group)) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Checks whether a value satisfies every member of one union branch
		 * (an intersection: A&B requires both). A single-element group is just
		 * an ordinary type check.
		 * @param mixed $value
		 * @param array<int, string> $group
		 * @return bool
		 */
		protected function valueSatisfiesGroup(mixed $value, array $group): bool {
			foreach ($group as $type) {
				if ($type === 'null') {
					if ($value !== null) {
						return false;
					}

					continue;
				}

				if ($this->isBuiltinType($type)) {
					if (!$this->valueSatisfiesBuiltinType($value, $type)) {
						return false;
					}

					continue;
				}

				if (!($value instanceof $type)) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Resolves a type from the container. Extracted so subclasses can swap the
		 * container without duplicating the resolution loop.
		 * @param class-string $type The fully qualified class or interface name to resolve
		 * @param array<string, mixed> $parameters Additional parameters for resolution
		 * @param MethodContextInterface|null $methodContext
		 * @return object|null The resolved instance, or null
		 */
		protected function resolveType(string $type, array $parameters, ?MethodContextInterface $methodContext): ?object {
			return $this->container->get($type, [], $methodContext);
		}
	}