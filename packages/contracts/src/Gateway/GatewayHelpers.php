<?php
	
	namespace Quellabs\Contracts\Gateway;
	
	trait GatewayHelpers {
		
		/**
		 * Safely retrieves a nested value from an array using dot notation.
		 * @param array<array-key, mixed> $data
		 * @param string $path
		 * @param mixed $default
		 * @return mixed
		 */
		private function arrayGet(array $data, string $path, mixed $default = null): mixed {
			$segments = explode('.', $path);
			$current  = $data;
			
			foreach ($segments as $segment) {
				if (!is_array($current) || !array_key_exists($segment, $current)) {
					return $default;
				}
				
				$current = $current[$segment];
			}
			
			return $current;
		}
		
		/**
		 * Coerces a mixed value to int, accepting int or numeric strings.
		 * Returns $default when the value cannot be meaningfully converted.
		 * @param mixed $value
		 * @param int $default
		 * @return int
		 */
		private function toInt(mixed $value, int $default = 0): int {
			if (is_int($value)) {
				return $value;
			}
			
			if (is_numeric($value)) {
				return (int)$value;
			}
			
			return $default;
		}
		
		/**
		 * Coerces a mixed value to float, accepting float, int, or numeric strings.
		 * Returns $default when the value cannot be meaningfully converted.
		 * @param mixed $value
		 * @param float $default
		 * @return float
		 */
		private function toFloat(mixed $value, float $default = 0.0): float {
			if (is_float($value)) {
				return $value;
			}
			
			if (is_int($value)) {
				return (float)$value;
			}
			
			if (is_numeric($value)) {
				return (float)$value;
			}
			
			return $default;
		}
		
		/**
		 * Coerces a mixed value to bool, accepting booleans and the canonical string/int forms.
		 * Returns $default when the value cannot be meaningfully converted.
		 * @param mixed $value
		 * @param bool $default
		 * @return bool
		 */
		private function toBool(mixed $value, bool $default = false): bool {
			if (is_bool($value)) {
				return $value;
			}
			
			if (is_int($value)) {
				return $value !== 0;
			}
			
			if (is_string($value)) {
				return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
			}
			
			return $default;
		}
		
		/**
		 * Coerces a mixed value to string, accepting strings and numerics.
		 * Returns $default when the value cannot be meaningfully represented as a string.
		 * @param mixed $value
		 * @param string $default
		 * @return string
		 */
		private function normalizeString(mixed $value, string $default = ''): string {
			if (is_string($value)) {
				return $value;
			}
			
			if (is_int($value) || is_float($value)) {
				return (string)$value;
			}
			
			return $default;
		}
	}