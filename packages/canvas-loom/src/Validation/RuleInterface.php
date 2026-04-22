<?php
	
	namespace Quellabs\Canvas\Loom\Validation;
	
	/**
	 * Interface for Loom validation rules.
	 * Implementations provide both PHP-side validation and JS emission
	 * for WakaForm client-side validation when client validation is enabled.
	 */
	interface RuleInterface {
		
		/**
		 * Validates the given value against this rule's criteria.
		 * @param mixed $value The value to validate
		 * @return bool True if validation passes, false if it fails
		 */
		public function validate(mixed $value): bool;
		
		/**
		 * Returns the error message to display when validation fails.
		 * @return string
		 */
		public function getError(): string;
		
		/**
		 * Returns true when this rule has a WakaForm client-side equivalent
		 * and toJs() will produce a valid constructor expression.
		 * Returns false for server-only rules — toJs() will not be called.
		 * @return bool
		 */
		public function wakaFormSupported(): bool;

		/**
		 * Emits the WakaForm JS constructor expression for this rule,
		 * e.g. "new NotBlank()" or "new MinLength(8)".
		 * Only called when wakaFormSupported() returns true.
		 * @return string
		 */
		public function toJs(): string;
	}