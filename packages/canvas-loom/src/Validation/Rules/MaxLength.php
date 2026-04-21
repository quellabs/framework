<?php
	
	namespace Quellabs\Canvas\Loom\Validation\Rules;
	
	/**
	 * Fails if the string length is greater than n characters.
	 * Empty values pass — combine with NotBlank if the field is required.
	 */
	class MaxLength extends RuleBase {
		
		/** @var int */
		private int $n;
		
		/**
		 * @param int         $n       Maximum number of characters
		 * @param string|null $message Optional custom error message
		 */
		public function __construct(int $n, ?string $message = null) {
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
			
			return strlen((string)$value) <= $this->n;
		}
		
		/**
		 * @inheritDoc
		 */
		public function getError(): string {
			return $this->message
				?? $this->interpolate('This value is too long. It should have {{ n }} characters or less.', ['n' => $this->n]);
		}
		
		/**
		 * @inheritDoc
		 */
		public function toJs(): ?string {
			return "new MaxLength({$this->n})";
		}
	}