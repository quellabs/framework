<?php
	
	namespace Quellabs\Canvas\Loom\Validation\Rules;
	
	/**
	 * Fails if the value is not a valid email address.
	 * Empty values pass — combine with NotBlank if the field is required.
	 */
	class Email extends RuleBase {
		
		/**
		 * @inheritDoc
		 */
		public function validate(mixed $value): bool {
			if ($value === null || $value === '') {
				return true;
			}
			
			return (bool)filter_var($value, FILTER_VALIDATE_EMAIL);
		}
		
		/**
		 * @inheritDoc
		 */
		public function getError(): string {
			return $this->message ?? 'This value is not a valid email address.';
		}
		
		/**
		 * @inheritDoc
		 */
		public function wakaFormSupported(): bool {
			return true;
		}
		
		/**
		 * @inheritDoc
		 */
		public function toJs(): string {
			return 'new Email()';
		}
	}