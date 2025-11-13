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
		 * Resolves and returns arguments for a specific method.
		 * @param object|string $class The class instance or class name
		 * @param string $methodName The name of the method to resolve arguments for
		 * @param array $parameters Optional array of parameters to use for resolution
		 * @return array The resolved arguments array ready for method invocation
		 */
		public function getMethodArguments(object|string $class, string $methodName, array $parameters = []): array {
			// Fetch the name of the class - handle both object instances and class name strings
			$className = is_object($class) ? get_class($class) : $class;
			
			// Create a context object to track method resolution state and metadata
			$methodContext = new MethodContext($className, $methodName);
			
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
					$allParameterIndex = $index; // Remember where the all parameter is located
					$arguments[] = null; // Placeholder - will be filled later with all parameter data
					continue; // Skip normal parameter resolution for this parameter
				}
				
				// Resolve the current parameter using the provided parameters and context
				// This handles type conversion, default values, dependency injection, etc.
				$arguments[] = $this->resolveParameter($param, $parameters, $methodContext, $className, $methodName);
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
		 * @param array $param Parameter metadata (name, types, default_value, etc.)
		 * @param array $parameters User-provided parameter values
		 * @param MethodContext|null $methodContext Context object for dependency injection
		 * @param string $className Class name for error reporting
		 * @param string $methodName Method name for error reporting
		 * @return mixed The resolved parameter value
		 */
		protected function resolveParameter(
			array $param,
			array $parameters,
			?MethodContext $methodContext,
			string $className,
			string $methodName
		): mixed {
			$paramName = $param['name'];
			$paramTypes = $param['types'] ?? [];
			
			// Strategy 1: Direct parameter name match
			// Check if user provided exact parameter name
			if (isset($parameters[$paramName])) {
				return $parameters[$paramName];
			}
			
			// Strategy 2: Snake case conversion attempt
			// Handle camelCase method params with snake_case user input
			if (isset($parameters[$this->camelToSnake($paramName)])) {
				return $parameters[$this->camelToSnake($paramName)];
			}
			
			// Strategy 3: Dependency injection resolution
			// Attempt to autowire parameter using type hints and container
			$resolved = $this->resolveParameterFromTypes($paramName, $paramTypes, $methodContext);
			
			if ($resolved !== null) {
				return $resolved;
			}
			
			// Strategy 4: Use parameter's default value
			// Fall back to method signature default if available
			if (array_key_exists('default_value', $param)) {
				return $param['default_value'];
			}
			
			// All strategies failed - parameter is required but unresolvable
			throw new \RuntimeException("Cannot autowire parameter '$paramName' for $className::$methodName");
		}
		
		/**
		 * Determines if a parameter is the magic **all parameter
		 * This checks if the parameter name is 'all' and has a type hint of 'array'
		 * @param array $param Parameter metadata
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
		 * Attempts to resolve a parameter value by trying each type hint in order
		 * @param string $paramName Parameter name for better error messages
		 * @param array $types Array of type hints/class names to attempt resolution
		 * @param MethodContext|null $methodContext
		 * @return mixed The resolved instance
		 * @throws \RuntimeException If no types could be resolved
		 */
		protected function resolveParameterFromTypes(string $paramName, array $types, ?MethodContext $methodContext = null): mixed {
			// Early return if no types provided - nothing to resolve
			if (empty($types)) {
				return null;
			}
			
			// Track resolution failures for detailed error reporting
			$failures = [];
			
			// Filter out built-in PHP types (int, string, bool, etc.) and null type
			// as these cannot be resolved through dependency injection
			$resolvableTypes = array_filter($types, fn($type) => !$this->isBuiltinType($type) && $type !== 'null');
			
			// If all types are built-in or null, there's nothing we can resolve via DI
			if (empty($resolvableTypes)) {
				return null;
			}
			
			// Attempt to resolve each resolvable type in order
			foreach ($resolvableTypes as $type) {
				try {
					// Try to get an instance of this type from the container
					$instance = $this->container->get($type, [], $methodContext);
					
					// If we successfully got a non-null instance, return it immediately
					// This implements a "first successful resolution wins" strategy
					if ($instance !== null) {
						return $instance;
					}
				} catch (\Throwable $e) {
					// Store the failure reason for this type to include in final error message
					// This helps with debugging by showing why each type failed to resolve
					$failures[$type] = $e->getMessage();
				}
			}
			
			// If we reach here, none of the types could be resolved successfully.
			// Throw a comprehensive error with details about all failed attempts
			$contextInfo = $methodContext ? "{$methodContext->getClassName()}::{$methodContext->getMethodName()}" : "unknown context";
			
			throw new \RuntimeException(
				"Cannot resolve parameter '{$paramName}' in {$contextInfo}:\n" .
				implode("\n", $failures)
			);
		}
		
		/**
		 * Get the parameters of a method including type hints and default values
		 * @param string $className
		 * @param string $methodName
		 * @return array
		 */
		protected function getMethodParameters(string $className, string $methodName): array {
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
					return [];
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