<?php
	
	namespace Quellabs\DependencyInjection\Autowiring;
	
	use Quellabs\Contracts\DependencyInjection\Container;
	
	/**
	 * Handles dependency injection through reflection
	 */
	class Autowirer {
		
		/**
		 * @var Container
		 */
		private Container $container;
		
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
		 * @param Container $container
		 */
		public function __construct(Container $container) {
			$this->container = $container;
		}
		
		/**
		 * Resolves and returns arguments for a specific method by matching parameters
		 * with provided values, attempting dependency injection, or using defaults.
		 * @param object|string $class
		 * @param string $methodName The method name to resolve arguments for
		 * @param array $parameters Optional associative array of parameter values
		 * @return array Ordered array of resolved arguments for the method
		 */
		public function getMethodArguments(object|string $class, string $methodName, array $parameters = []): array {
			// Fetch the name of the class
			$className = is_object($class) ? get_class($class) : $class;
			
			// Create a context
			$methodContext = new MethodContext($className, $methodName);
			
			// Get method parameter metadata (name, types, defaults, etc.)
			$methodParams = $this->getMethodParameters($className, $methodName);
			
			// Process each method parameter in order
			$arguments = [];

			foreach ($methodParams as $param) {
				$paramName = $param['name'];
				$paramTypes = $param['types'] ?? [];
				
				// Strategy 1: Check if parameter value is directly provided
				if (isset($parameters[$paramName])) {
					$arguments[] = $parameters[$paramName];
					continue;
				}
				
				// Strategy 2: Try camelCase version of parameter name
				// (handles snake_case to camelCase conversion)
				if (isset($parameters[$this->camelToSnake($paramName)])) {
					$arguments[] = $parameters[$this->camelToSnake($paramName)];
					continue;
				}
				
				// Strategy 3: Attempt dependency injection using type hints
				// Tries to resolve parameter from container/service locator
				$resolvedValue = $this->resolveParameterFromTypes($paramTypes, $methodContext);
				
				if ($resolvedValue !== null) {
					$arguments[] = $resolvedValue;
					continue;
				}
				
				// Strategy 4: Fall back to parameter's default value if defined
				if (array_key_exists('default_value', $param)) {
					$arguments[] = $param['default_value'];
					continue;
				}
				
				// Strategy 5: All resolution strategies failed - throw exception
				// This indicates a required parameter that cannot be satisfied
				throw new \RuntimeException("Cannot autowire parameter '$paramName' for $className::$methodName");
			}
			
			// Return the complete argument list in the correct order
			return $arguments;
		}
		
		/**
		 * Attempts to resolve a parameter value by trying each type hint in order
		 * through the dependency injection container.
		 * @param array $types Array of type hints/class names to attempt resolution
		 * @param MethodContext|null $methodContext
		 * @return mixed The resolved instance, or null if no type could be resolved
		 */
		protected function resolveParameterFromTypes(array $types, ?MethodContext $methodContext=null): mixed {
			// Skip resolution if no types are provided
			if (empty($types)) {
				return null;
			}
			
			// Attempt to resolve each type in priority order
			foreach ($types as $type) {
				// Skip built-in PHP types (string, int, bool, etc.) as they
				// cannot be resolved through dependency injection
				if ($this->isBuiltinType($type)) {
					continue;
				}
				
				// Skip null type as it's not resolvable
				if ($type === 'null') {
					continue;
				}
				
				try {
					// Attempt to retrieve instance from the DI container
					$instance = $this->container->get($type, [], $methodContext);
					
					// Return the first successfully resolved instance
					if ($instance !== null) {
						return $instance;
					}
				} catch (\Throwable $e) {
					// Silently continue to next type - this is expected behavior
					// for union types where not all types may be available
					continue;
				}
			}
			
			// No types could be resolved - let caller handle this scenario
			return null;
		}
		
		/**
		 * Get the parameters of a method including type hints and default values
		 * @param string $className
		 * @param string $methodName
		 * @return array
		 */
		protected function getMethodParameters(string $className, string $methodName): array {
			try {
				$result = [];
				
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
					return [];
				}
				
				// Process each parameter
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
				
				return $result;
			} catch (\ReflectionException $e) {
				return [];
			}
		}
		
		/**
		 * Extract all possible types from a ReflectionType
		 * Handles union types, intersection types, and named types
		 * @param \ReflectionType $type
		 * @return array
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
		 * Converts a snake_case string to camelCase format.
		 * @param string $snakeStr The snake_case string to convert
		 * @return string The converted camelCase string
		 */
		protected function snakeToCamel(string $snakeStr): string {
			// Split the string by underscores to get individual words
			$words = explode('_', $snakeStr);
			
			// Keep the first word lowercase, capitalize the first letter of remaining words
			return $words[0] . implode('', array_map('ucfirst', array_slice($words, 1)));
		}
		
		/**
		 * Convert camelCase string to snake_case
		 * @param string $input The camelCase string to convert
		 * @return string The converted snake_case string
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
			return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
		}
	}