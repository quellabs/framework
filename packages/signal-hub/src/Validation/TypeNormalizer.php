<?php
	
	namespace Quellabs\SignalHub\Validation;
	
	/**
	 * Type normalizer for consistent type handling
	 */
	class TypeNormalizer {
		/**
		 * @var array Type aliases mapping
		 */
		private static array $typeMap = [
			'integer' => 'int',
			'boolean' => 'bool',
			'double'  => 'float',
		];
		
		/**
		 * Normalizes type strings to consistent notation
		 * @param string $type Raw type string
		 * @return string Normalized type string
		 */
		public static function normalize(string $type): string {
			return self::$typeMap[$type] ?? $type;
		}
	}