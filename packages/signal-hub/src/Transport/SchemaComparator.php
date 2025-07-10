<?php
	
	namespace Quellabs\SignalHub\Transport;
	
	/**
	 * This class handles the comparison of signal schemas to determine if they are
	 * compatible for connections. It can perform both strict and intelligent comparisons.
	 */
	class SchemaComparator {
		
		/**
		 * Compare mode constants
		 */
		public const string MODE_STRICT = 'strict';
		public const string MODE_INTELLIGENT = 'intelligent';
		
		/**
		 * @var string Comparison mode
		 */
		private string $mode;
		
		/**
		 * Constructor
		 * @param string $mode Comparison mode (strict or intelligent)
		 */
		public function __construct(string $mode = self::MODE_STRICT) {
			$this->mode = $mode;
		}
		
		/**
		 * Check if two schemas are compatible
		 * @param array $signalSchema Schema from the signal
		 * @param array $receiverSchema Schema from the receiver (method/callable)
		 * @return bool True if schemas are compatible
		 */
		public function areCompatible(array $signalSchema, array $receiverSchema): bool {
			return match ($this->mode) {
				self::MODE_STRICT => $this->strictComparison($signalSchema, $receiverSchema),
				self::MODE_INTELLIGENT => $this->intelligentComparison($signalSchema, $receiverSchema),
				default => throw new \InvalidArgumentException("Invalid comparison mode: {$this->mode}")
			};
		}
		
		/**
		 * Get detailed comparison information
		 * @param array $signalSchema Schema from signal
		 * @param array $receiverSchema Schema from receiver
		 * @return array Detailed comparison result
		 */
		public function getComparisonDetails(array $signalSchema, array $receiverSchema): array {
			$details = [
				'compatible'            => false,
				'mode'                  => $this->mode,
				'parameter_count_match' => count($signalSchema) === count($receiverSchema),
				'exact_match'           => $signalSchema === $receiverSchema,
				'parameter_details'     => []
			];
			
			// Check overall compatibility
			$details['compatible'] = $this->areCompatible($signalSchema, $receiverSchema);
			
			// Analyze each parameter
			$maxParams = max(count($signalSchema), count($receiverSchema));
			
			for ($i = 0; $i < $maxParams; $i++) {
				$signalType = $signalSchema[$i] ?? null;
				$receiverType = $receiverSchema[$i] ?? null;
				
				$paramDetail = [
					'index'         => $i,
					'signal_type'   => $signalType,
					'receiver_type' => $receiverType,
					'compatible'    => false
				];
				
				if ($signalType !== null && $receiverType !== null) {
					$paramDetail['compatible'] = $this->areTypesCompatible($signalType, $receiverType);
				}
				
				$details['parameter_details'][] = $paramDetail;
			}
			
			return $details;
		}
		
		/**
		 * Set comparison mode
		 * @param string $mode Comparison mode
		 * @return self
		 */
		public function setMode(string $mode): self {
			$this->mode = $mode;
			return $this;
		}
		
		/**
		 * Get current comparison mode
		 * @return string Current mode
		 */
		public function getMode(): string {
			return $this->mode;
		}
		
		/**
		 * Strict schema comparison - schemas must be exactly identical
		 * @param array $signalSchema Schema from signal
		 * @param array $receiverSchema Schema from receiver
		 * @return bool True if exactly identical
		 */
		private function strictComparison(array $signalSchema, array $receiverSchema): bool {
			return $signalSchema === $receiverSchema;
		}
		
		/**
		 * Intelligent schema comparison - handles type variations and compatibility
		 * @param array $signalSchema Schema from signal
		 * @param array $receiverSchema Schema from receiver
		 * @return bool True if compatible
		 */
		private function intelligentComparison(array $signalSchema, array $receiverSchema): bool {
			// Quick check - if exactly equal, they're definitely compatible
			if ($signalSchema === $receiverSchema) {
				return true;
			}
			
			// Check parameter count
			if (count($signalSchema) !== count($receiverSchema)) {
				return false;
			}
			
			// Check each parameter type
			foreach ($signalSchema as $index => $signalType) {
				if (!isset($receiverSchema[$index])) {
					return false;
				}
				
				$receiverType = $receiverSchema[$index];
				
				if (!$this->areTypesCompatible($signalType, $receiverType)) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * Check if two schema types are compatible
		 * @param mixed $signalType Type from signal schema
		 * @param mixed $receiverType Type from receiver schema
		 * @return bool True if compatible
		 */
		private function areTypesCompatible($signalType, $receiverType): bool {
			// Exact match
			if ($signalType === $receiverType) {
				return true;
			}
			
			// Both are arrays (object schemas) - consider compatible for now
			if (is_array($signalType) && is_array($receiverType)) {
				return $this->areObjectSchemasCompatible($signalType, $receiverType);
			}
			
			// Both are strings (primitive types)
			if (is_string($signalType) && is_string($receiverType)) {
				return $this->areStringTypesCompatible($signalType, $receiverType);
			}
			
			// Mixed types (array vs string) are not compatible
			return false;
		}
		
		/**
		 * Check if two object schemas are compatible
		 * @param array $signalObjectSchema Object schema from signal
		 * @param array $receiverObjectSchema Object schema from receiver
		 * @return bool True if compatible
		 */
		private function areObjectSchemasCompatible(array $signalObjectSchema, array $receiverObjectSchema): bool {
			// Check if both schemas have the same number of properties
			// This ensures structural compatibility at the top level
			if (count($signalObjectSchema) !== count($receiverObjectSchema)) {
				return false;
			}
			
			// Iterate through each property in the signal schema
			// to verify it exists and is compatible in the receiver schema
			foreach ($signalObjectSchema as $propertyName => $signalPropertyType) {
				// Verify the property exists in the receiver schema
				// Missing properties indicate incompatible schemas
				if (!array_key_exists($propertyName, $receiverObjectSchema)) {
					return false;
				}
				
				// Get the corresponding property type from receiver schema
				$receiverPropertyType = $receiverObjectSchema[$propertyName];
				
				// Recursively check if the property types are compatible
				// This handles nested objects and complex type structures
				if (!$this->areTypesCompatible($signalPropertyType, $receiverPropertyType)) {
					return false;
				}
			}
			
			// All properties exist and are compatible
			return true;
		}
		
		/**
		 * Check if two string types are compatible
		 * @param string $signalType Type from signal
		 * @param string $receiverType Type from receiver
		 * @return bool True if compatible
		 */
		private function areStringTypesCompatible(string $signalType, string $receiverType): bool {
			// Normalize types first
			$signalType = $this->normalizeType($signalType);
			$receiverType = $this->normalizeType($receiverType);
			
			// Exact match after normalization
			if ($signalType === $receiverType) {
				return true;
			}
			
			// Handle 'mixed' type - compatible with anything
			if ($signalType === 'mixed' || $receiverType === 'mixed') {
				return true;
			}
			
			// Handle union types
			if (str_contains($signalType, '|') || str_contains($receiverType, '|')) {
				return $this->areUnionTypesCompatible($signalType, $receiverType);
			}
			
			// Handle object/class compatibility
			if ($signalType === 'object' || $receiverType === 'object') {
				return true; // 'object' is compatible with any class
			}
			
			// Handle nullable variations: string vs string|null
			if ($this->isNullableVariation($signalType, $receiverType)) {
				return true;
			}
			
			return false;
		}
		
		/**
		 * Check if union types are compatible
		 * @param string $type1 First union type
		 * @param string $type2 Second union type
		 * @return bool True if compatible
		 */
		private function areUnionTypesCompatible(string $type1, string $type2): bool {
			$types1 = array_map('trim', explode('|', $type1));
			$types2 = array_map('trim', explode('|', $type2));
			
			// Normalize all types
			$types1 = array_map([$this, 'normalizeType'], $types1);
			$types2 = array_map([$this, 'normalizeType'], $types2);
			
			// Check if there's any overlap
			return !empty(array_intersect($types1, $types2));
		}
		
		/**
		 * Check if types are nullable variations of each other
		 * @param string $type1 First type
		 * @param string $type2 Second type
		 * @return bool True if one is nullable version of the other
		 */
		private function isNullableVariation(string $type1, string $type2): bool {
			// Remove null from union types
			$type1Clean = str_replace(['|null', 'null|'], '', $type1);
			$type2Clean = str_replace(['|null', 'null|'], '', $type2);
			
			// If one becomes empty (was just 'null'), they're not variations
			if (empty($type1Clean) || empty($type2Clean)) {
				return false;
			}
			
			// Check if the non-null parts are the same
			return $type1Clean === $type2Clean;
		}
		
		/**
		 * Normalize type names to canonical forms
		 * @param string $type Type name to normalize
		 * @return string Normalized type name
		 */
		private function normalizeType(string $type): string {
			// Map alternative type names to canonical forms
			$typeMap = [
				'integer' => 'int',
				'boolean' => 'bool',
				'double'  => 'float'
			];
			
			return $typeMap[$type] ?? $type;
		}
	}