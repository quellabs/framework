<?php
	
	namespace Quellabs\Canvas\Loom\Validation\Rules;
	
	use Quellabs\Canvas\Loom\Validation\RuleInterface;
	
	/**
	 * Passes if at least one of the given rules passes for the value.
	 * Useful for fields that accept multiple valid formats, e.g. email or phone.
	 */
	class AtLeastOneOf extends RuleBase {
		
		/** @var RuleInterface[] */
		private array $rules;
		
		/**
		 * @param RuleInterface[] $rules   Rules of which at least one must pass
		 * @param string|null     $message Optional custom error message
		 */
		public function __construct(array $rules, ?string $message = null) {
			parent::__construct($message);
			$this->rules = $rules;
		}
		
		/**
		 * @inheritDoc
		 */
		public function validate(mixed $value): bool {
			foreach ($this->rules as $rule) {
				if ($rule->validate($value)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * @inheritDoc
		 */
		public function getError(): string {
			return $this->message ?? 'At least one of the conditions must be satisfied.';
		}
	}