<?php
	
	namespace Quellabs\Canvas\Loom\Validation\Rules;
	
	/**
	 * Fails if the string length is less than n characters.
	 * Empty values pass — combine with NotBlank if the field is required.
	 */
	class MinLength extends RuleBase {
		
		/** @var int */
		private int $n;
		
		/**
		 * @param int         $n       Minimum number of characters
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
			
			return strlen((string)$value) >= $this->n;
		}
		
		/**
		 * @inheritDoc
		 */
		public function getError(): string {
			return $this->message
				?? $this->interpolate('This value is too short. It should have {{ n }} characters or more.', ['n' => $this->n]);
		}
		
		/**
		 * @inheritDoc
		 */
		public function toJs(): ?string {
			return "new MinLength({$this->n})";
		}
	}