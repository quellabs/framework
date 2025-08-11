<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Normalizer;
	
	/**
	 * JsonNormalizer handles conversion between JSON strings and PHP arrays/objects.
	 * The normalizer uses json_decode() and json_encode() for bidirectional conversion,
	 * with JSON_UNESCAPED_UNICODE flag to preserve Unicode characters in their readable form.
	 */
	class JsonNormalizer implements NormalizerInterface {
		
		/**
		 * The value to be normalized or denormalized.
		 * Can be either a JSON string or a PHP array/object.
		 * @var mixed
		 */
		protected mixed $value;
		
		/**
		 * Sets the value to normalize/denormalize
		 * @param mixed $value Either a JSON string (from database) or a PHP array/object (from entity)
		 * @return void
		 */
		public function setValue($value): void {
			$this->value = $value;
		}
		
		/**
		 * Normalize converts a database JSON string into a PHP array/object for use in entities.
		 * @return array|null Returns a PHP array representation of the JSON data
		 */
		public function normalize(): ?array {
			// Return null for null values
			if (is_null($this->value)) {
				return null;
			}
			
			// Decode JSON string to PHP array (associative = true)
			return json_decode($this->value, true);
		}
		
		/**
		 * Denormalize converts a PHP array/object into a JSON string for database storage.
		 * @return string|null A JSON string representation of the data, or null if the input is null
		 */
		public function denormalize(): ?string {
			// Return null if the input value is null
			if ($this->value === null) {
				return null;
			}
			
			// Encode the PHP array/object to JSON string with Unicode preservation
			return json_encode($this->value, JSON_UNESCAPED_UNICODE);
		}
	}