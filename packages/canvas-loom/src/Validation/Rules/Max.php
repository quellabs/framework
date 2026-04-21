<?php
	
	namespace Quellabs\Canvas\Loom\Validation\Rules;
	
	/**
	 * Fails if the numeric value is greater than n.
	 * Empty values pass — combine with NotBlank if the field is required.
	 */
	class Max extends RuleBase {
		
		/** @var int|float */
		private int|float $n;
		
		/**
		 * @param int|float   $n       Maximum allowed value
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
			
			return (float)$value <= $this->n;
		}
		
		/**
		 * @inheritDoc
		 */
		public function getError(): string {
			return $this->message
				?? $this->interpolate('This value should be {{ max }} or less.', ['max' => $this->n]);
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
			return "new Max({$this->n})";
		}
	}