<?php
	
	namespace Quellabs\Canvas\Loom\Validation\Rules;
	
	/**
	 * Fails if the value is null, an empty string, or whitespace only.
	 */
	class NotBlank extends RuleBase {
		
		/**
		 * @inheritDoc
		 */
		public function validate(mixed $value): bool {
			if ($value === null) {
				return false;
			}
			
			return strlen(trim((string)$value)) > 0;
		}
		
		/**
		 * @inheritDoc
		 */
		public function getError(): string {
			return $this->message ?? 'This value should not be blank.';
		}
		
		/**
		 * @inheritDoc
		 */
		public function toJs(): ?string {
			return 'new NotBlank()';
		}
	}