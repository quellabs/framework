<?php
	
	namespace Quellabs\Canvas\Loom\Validation\Rules;
	
	/**
	 * Fails if the value is not in the given set of allowed values.
	 * Empty values pass — combine with NotBlank if the field is required.
	 */
	class In extends RuleBase {
		
		/** @var array<scalar> */
		private array $allowed;
		
		/**
		 * @param array<scalar> $allowed  List of allowed values
		 * @param string|null   $message  Optional custom error message
		 */
		public function __construct(array $allowed, ?string $message = null) {
			parent::__construct($message);
			$this->allowed = $allowed;
		}
		
		/**
		 * @inheritDoc
		 */
		public function validate(mixed $value): bool {
			if ($value === null || $value === '') {
				return true;
			}
			
			return in_array($value, $this->allowed, strict: false);
		}
		
		/**
		 * @inheritDoc
		 */
		public function getError(): string {
			return $this->message ?? 'This value is not valid.';
		}
		
		/**
		 * @inheritDoc
		 */
		public function toJs(): ?string {
			$encoded = json_encode(array_values($this->allowed));
			return "new In({$encoded})";
		}
	}