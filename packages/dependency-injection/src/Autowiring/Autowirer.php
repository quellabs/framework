<?php
	
	namespace Quellabs\DependencyInjection\Autowiring;
	
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	
	/**
	 * class Autowirer
	 *
	 * @phpstan-type ParameterMeta array{
	 *     name: string,
	 *     types?: array<int, string>,
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
			
			foreach ($methodParams as $index => $param) {
				// Check if this parameter is a special "all parameter" type
				// This might be a parameter that receives all other resolved parameters
				if ($this->isAllParameter($param)) {
					$allParameterIndex = $index;
					$arguments[] = null;
					continue;
				}
				
				// Set the parameter that we're currently resolving
				$methodContext->setCurrentParameterName($param['name']);
				
				// Resolve the current parameter using the provided parameters and context
				// This handles type conversion, default values, dependency injection, etc.
				$arguments[] = $this->resolveParameter($param, $parameters, $methodContext, $className, $methodName);
			}
			
			// Reset the parameter that we're currently resolving
			$methodContext->setCurrentParameterName(null);
			
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
			
			// Strategy 1: Direct parameter name match
			if (isset($parameters[$paramName])) {
				return $parameters[$paramName];
			}
			
			// Strategy 2: Snake case conversion
			if (isset($parameters[$this->camelToSnake($paramName)])) {
				return $parameters[$this->camelToSnake($paramName)];
			}
			
			// Strategy 3: Dependency injection — sentinel means unresolved, null means resolved-to-null
			$resolved = $this->resolveParameterFromTypes($paramName, $paramTypes, $methodContext);
			
			if ($resolved !== self::unresolved()) {
				return $resolved;
			}
			
			// Strategy 4: Default value from method signature
			if (array_key_exists('default_value', $param)) {
				return $param['default_value'];
			}
			
			// Strategy 5: Empty array for array-typed parameters
			// Prevents a crash in CakePHP Database connection when autowiring the config
			if (in_array("array", $paramTypes, true)) {
				return [];
			}
			
			throw new \RuntimeException("Cannot autowire parameter '$paramName' for $className::$methodName");
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
		 * Tries to resolve a parameter against each of its type hints in order.
		 * Returns the unresolved sentinel when no type could be resolved.
		 * Returns null when a provider resolved the type to null (e.g. entity not found),
		 * which the caller propagates without falling through to further strategies.
		 * @param string $paramName Parameter name for error messages
		 * @param array<int, string> $types Array of type hints to attempt resolution for
		 * @param MethodContextInterface|null $methodContext
		 * @return mixed The resolved value, or the unresolved sentinel
		 */
		protected function resolveParameterFromTypes(string $paramName, array $types, ?MethodContextInterface $methodContext = null): mixed {
			// Early return sentinel if no types provided - nothing to resolve
			if (empty($types)) {
				return self::unresolved();
			}
			
			// Filter out built-in PHP types (int, string, bool, etc.) and null type
			// as these cannot be resolved through dependency injection
			/** @var list<class-string> $resolvableTypes */
			$resolvableTypes = array_values(array_filter($types, fn($type) => !$this->isBuiltinType($type) && $type !== 'null'));
			
			// If all types are built-in or null, there's nothing we can resolve via DI
			if (empty($resolvableTypes)) {
				return self::unresolved();
			}
			
			// Attempt to resolve each resolvable type in order
			foreach ($resolvableTypes as $type) {
				try {
					// Try to get an instance of this type from the container
					$instance = $this->resolveType($type, [], $methodContext);
					
					// Non-null instance: resolved successfully
					if ($instance !== null) {
						return $instance;
					}
				} catch (\Throwable) {
					// Resolution threw — this type is not resolvable, try the next one
				}
			}
			
			// No type could be resolved; signal to resolveParameter() to try further strategies
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
			
			try {
				// New reflection class to get information about the class name
				$reflectionClass = new \ReflectionClass($className);
				
				// Determine which method to reflect
				if (empty($methodName) || $methodName === '__construct') {
					$methodReflector = $reflectionClass->getConstructor();
				} else {
					$methodReflector = $reflectionClass->getMethod($methodName);
				}
				
				// Return an empty array when the method does not exist
				if (!$methodReflector) {
					return $this->parameterCache[$cacheKey] = [];
				}
				
				// Process each parameter
				$result = [];
				
				foreach ($methodReflector->getParameters() as $parameter) {
					// Get the name of the parameter
					$param = ['name' => $parameter->getName()];
					
					// Get the type of the parameter if available
					if ($parameter->hasType()) {
						$param['types'] = $this->extractTypes($parameter->getType());
					}
					
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
			} catch (\ReflectionException $e) {
				return $this->parameterCache[$cacheKey] = [];
			}
		}
		
		/**
		 * Extract all possible types from a ReflectionType
		 * Handles union types, intersection types, and named types
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
		 * Check if a type is a built-in PHP type that can be used in parameter lists
		 * @param string $type
		 * @return bool
		 */
		protected function isBuiltinType(string $type): bool {
			return in_array($type, self::BUILTIN_TYPES);
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
		
		/**
		 * Converts camelCase to snake_case.
		 * @param string $input
		 * @return string
		 */
		protected function camelToSnake(string $input): string {
			// Handle empty strings - return as-is to avoid errors
			if (empty($input)) {
				return $input;
			}
			
			// Use regex to find uppercase letters that are not at the start of the string
			// (?<!^) - negative lookbehind assertion: ensures we don't match the first character
			// [A-Z]  - matches any uppercase letter A through Z
			// '_$0'  - replacement: underscore followed by the matched uppercase letter
			// Then convert the entire result to lowercase
			return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input) ?? $input);
		}
	}