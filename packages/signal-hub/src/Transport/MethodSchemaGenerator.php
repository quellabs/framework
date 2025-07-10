<?php
	
	namespace Quellabs\SignalHub\Transport;
	
	/**
	 * This class analyzes method parameters using PHP reflection and converts
	 * them into schema definitions compatible with the SignalHub transport system.
	 */
	class MethodSchemaGenerator {
		
		/**
		 * Track processed classes to prevent infinite recursion
		 * @var array
		 */
		private array $processingStack = [];
		
		/**
		 * Schema generator for class types
		 * @var SchemaGenerator
		 */
		private SchemaGenerator $schemaGenerator;
		
		/**
		 * MethodSchemaGenerator constructor
		 */
		public function __construct() {
			$this->schemaGenerator = new SchemaGenerator();
		}
		
		/**
		 * Generate schema from method reflection
		 * @param \ReflectionMethod $method The method to analyze
		 * @param bool $publicOnly Whether to only include public properties (default: true)
		 * @return array Schema array indexed by parameter position
		 * @throws \RuntimeException If circular reference detected
		 */
		public function generateFromMethod(\ReflectionMethod $method, bool $publicOnly=true): array {
			// Reset processing stack for each new method
			$this->processingStack = [];
			
			return array_map(function ($param) use ($publicOnly) {
				return $this->generateParameterSchema($param, $publicOnly);
			}, $method->getParameters());
		}
		
		/**
		 * Generate schema from function reflection
		 * @param \ReflectionFunction $function The function to analyze
		 * @param bool $publicOnly Whether to only include public properties (default: true)
		 * @return array Schema array indexed by parameter position
		 * @throws \RuntimeException If circular reference detected
		 */
		public function generateFromFunction(\ReflectionFunction $function, bool $publicOnly=true): array {
			// Reset processing stack for each new function
			$this->processingStack = [];
			
			return array_map(function ($param) use ($publicOnly) {
				return $this->generateParameterSchema($param, $publicOnly);
			}, $function->getParameters());
		}
		
		/**
		 * Generate schema from callable reflection
		 * @param callable $callable The callable to analyze
		 * @return array Schema array indexed by parameter position
		 * @throws \RuntimeException If callable cannot be reflected or circular reference detected
		 */
		public function generateFromCallable(callable $callable): array {
			try {
				// Handle different callable types
				if (is_array($callable) && count($callable) === 2) {
					// [$object, 'methodName'] or [ClassName::class, 'methodName']
					$reflection = new \ReflectionMethod($callable[0], $callable[1]);
					return $this->generateFromMethod($reflection);
				}
				
				// 'ClassName::methodName'
				if (is_string($callable) && str_contains($callable, '::')) {
					[$class, $method] = explode('::', $callable, 2);
					$reflection = new \ReflectionMethod($class, $method);
					return $this->generateFromMethod($reflection);
				}
				
				// Invokable object
				if (is_object($callable) && method_exists($callable, '__invoke')) {
					$reflection = new \ReflectionMethod($callable, '__invoke');
					return $this->generateFromMethod($reflection);
				}
				
				// Function or closure
				$reflection = new \ReflectionFunction($callable);
				return $this->generateFromFunction($reflection);
			} catch (\ReflectionException $e) {
				throw new \RuntimeException("Cannot generate schema from callable: " . $e->getMessage());
			}
		}
		
		/**
		 * Generate schema for a single parameter
		 * @param \ReflectionParameter $param The parameter to analyze
		 * @param bool $publicOnly Whether to only include public properties (default: true)
		 * @return string|array Returns string for primitives, array for classes
		 * @throws \RuntimeException If circular reference detected
		 */
		private function generateParameterSchema(\ReflectionParameter $param, bool $publicOnly): string|array {
			$type = $param->getType();
			
			if ($type === null) {
				return 'mixed';
			}
			
			// Handle union types (e.g., string|int|null)
			if ($type instanceof \ReflectionUnionType) {
				$types = [];
				
				foreach ($type->getTypes() as $unionType) {
					$typeName = $unionType->getName();
					
					if (class_exists($typeName)) {
						// For union types with classes, we can't include full schemas
						// so we represent them as 'object' or the class name
						$types[] = 'object';
					} else {
						$types[] = $this->normalizeTypeName($typeName);
					}
				}
				
				return implode('|', array_unique($types));
			}
			
			// Handle named types
			if ($type instanceof \ReflectionNamedType) {
				$typeName = $type->getName();
				
				// Check if it's a class type
				if (class_exists($typeName)) {
					$classSchema = $this->generateClassSchema($typeName, $publicOnly);
					
					// Handle nullable class types
					if ($type->allowsNull()) {
						// For nullable objects, we could represent this differently
						// For now, we'll just return the class schema
						return $classSchema;
					}
					
					return $classSchema;
				}
				
				// Handle primitive types
				$normalizedType = $this->normalizeTypeName($typeName);
				
				// Handle nullable primitive types
				if ($type->allowsNull() && $normalizedType !== 'mixed' && $normalizedType !== 'null') {
					return $normalizedType . '|null';
				}
				
				return $normalizedType;
			}
			
			return 'mixed';
		}
		
		/**
		 * Generate schema for a class type with circular reference protection
		 * @param string $className The class name to analyze
		 * @param bool $publicOnly Whether to only include public properties (default: true)
		 * @return array Class schema
		 * @throws \RuntimeException If circular reference detected
		 */
		private function generateClassSchema(string $className, bool $publicOnly): array {
			// Prevent infinite recursion by tracking processed classes
			if (in_array($className, $this->processingStack)) {
				throw new \RuntimeException("Circular reference detected for class: {$className}");
			}
			
			// Mark this class as being processed
			$this->processingStack[] = $className;
			
			try {
				// Use the existing SchemaGenerator to handle class schema generation
				$schema = $this->schemaGenerator->generateTypeSchema($className, $publicOnly);
				
				// If the schema generator returns a string (like 'object'), convert to array
				if (is_string($schema)) {
					return ['_type' => $schema];
				}
				
				return $schema;
			} finally {
				// Remove this class from the processing stack
				array_pop($this->processingStack);
			}
		}
		
		/**
		 * Normalize type names to canonical forms
		 * @param string $typeName The type name to normalize
		 * @return string The normalized type name
		 */
		private function normalizeTypeName(string $typeName): string {
			// Map alternative type names to canonical forms
			$typeMap = [
				'integer' => 'int',
				'boolean' => 'bool',
				'double'  => 'float'
			];
			
			return $typeMap[$typeName] ?? $typeName;
		}
	}