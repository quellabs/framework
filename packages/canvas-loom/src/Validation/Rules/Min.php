<?php
	
	namespace Quellabs\Canvas\Loom\Validation\Rules;
	
	/**
	 * Fails if the numeric value is less than n.
	 * Empty values pass — combine with NotBlank if the field is required.
	 */
	class Min extends RuleBase {
		
		/** @var int|float */
		private int|float $n;
		
		/**
		 * @param int|float   $n       Minimum allowed value
		 * @param string|null $message Optional custom error message
		 */
		public function __construct(int|float $n, ?string $message = null) {
			parent::__construct($message);
			$this->n = $n;
		}
		
		/**
		 * @inheritDoc
		 */
		public function validate(mixed $value): bool {
			if ($value === null || $value === '') {
				return true;
			}
			
			if (!is_numeric($value)) {
				return false;
			}
			
			return (float)$value >= $this->n;
		}
		
		/**
		 * @inheritDoc
		 */
		public function getError(): string {
			return $this->message
				?? $this->interpolate('This value should be {{ min }} or more.', ['min' => $this->n]);
		}
		
		/**
		 * @inheritDoc
		 */
		public function toJs(): ?string {
			return "new Min({$this->n})";
		}
	}