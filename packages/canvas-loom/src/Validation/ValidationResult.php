<?php
	
	namespace Quellabs\Canvas\Loom\Validation;
	
	/**
	 * Returned by Loom::validate().
	 * Carries the per-field error map and exposes convenience methods
	 * for use in controllers.
	 */
	class ValidationResult {
		
		/**
		 * @param array<string, string> $errors Associative array of fieldName => error message
		 */
		public function __construct(private readonly array $errors) {}
		
		/**
		 * Returns true when all fields passed validation.
		 * @return bool
		 */
		public function passes(): bool {
			return empty($this->errors);
		}
		
		/**
		 * Returns true when at least one field failed validation.
		 * @return bool
		 */
		public function fails(): bool {
			return !empty($this->errors);
		}
		
		/**
		 * Returns the full error map: ['fieldName' => 'error message'].
		 * Pass this directly to Loom::render() as part of the data array
		 * under the '_errors' key.
		 * @return array<string, string>
		 */
		public function errors(): array {
			return $this->errors;
		}
		
		/**
		 * Returns the error message for a single field, or null if the field passed.
		 * @param string $field
		 * @return string|null
		 */
		public function errorFor(string $field): ?string {
			return $this->errors[$field] ?? null;
		}
	}