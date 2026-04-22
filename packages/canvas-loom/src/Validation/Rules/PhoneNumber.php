<?php
	
	namespace Quellabs\Canvas\Loom\Validation\Rules;
	
	/**
	 * Fails if the value contains characters not valid in a phone number.
	 * Allows digits, spaces, commas, periods, hyphens, and plus signs.
	 * Empty values pass — combine with NotBlank if the field is required.
	 */
	class PhoneNumber extends RuleBase {
		
		/**
		 * @inheritDoc
		 */
		public function validate(mixed $value): bool {
			if ($value === null || $value === '') {
				return true;
			}
			
			return strcmp(preg_replace('/[^0-9\s,.\-+]/', '', $value), $value) === 0;
		}
		
		/**
		 * @inheritDoc
		 */
		public function getError(): string {
			return $this->message ?? 'This value is not a valid phone number.';
		}
	}