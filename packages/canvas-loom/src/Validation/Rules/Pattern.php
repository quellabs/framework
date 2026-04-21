<?php
	
	namespace Quellabs\Canvas\Loom\Validation\Rules;
	
	/**
	 * Fails if the value does not match the given regular expression.
	 * Empty values pass — combine with NotBlank if the field is required.
	 *
	 * The pattern must be a valid PHP regex including delimiters, e.g. '/^[A-Z]+$/i'.
	 * toJs() converts it to a JS RegExp literal by stripping the PHP delimiters
	 * and transferring the flags, e.g. /^[A-Z]+$/i.
	 */
	class Pattern extends RuleBase {
		
		/** @var string Full PHP regex including delimiters, e.g. '/^[A-Z]+$/i' */
		private string $pattern;
		
		/**
		 * @param string      $pattern  PHP regex with delimiters
		 * @param string|null $message  Optional custom error message
		 */
		public function __construct(string $pattern, ?string $message = null) {
			parent::__construct($message);
			$this->pattern = $pattern;
		}
		
		/**
		 * @inheritDoc
		 */
		public function validate(mixed $value): bool {
			if ($value === null || $value === '') {
				return true;
			}
			
			return (bool)preg_match($this->pattern, (string)$value);
		}
		
		/**
		 * @inheritDoc
		 */
		public function getError(): string {
			return $this->message ?? 'This value does not match the required pattern.';
		}
		
		/**
		 * Converts the PHP regex to a JS RegExp literal.
		 * Strips the delimiter and transfers any flags, e.g.:
		 *   '/^[A-Z]+$/i'  →  new Pattern(/^[A-Z]+$/i)
		 *   '#^\d{4}$#'    →  new Pattern(/^\d{4}$/)
		 * @inheritDoc
		 */
		public function toJs(): ?string {
			// The first character is the delimiter — find where it ends.
			$delimiter = $this->pattern[0];
			
			// Locate the closing delimiter (last occurrence, since flags follow it)
			$close = strrpos($this->pattern, $delimiter, 1);
			
			if ($close === false || $close === 0) {
				// Malformed pattern — skip rather than emit broken JS
				return null;
			}
			
			$body  = substr($this->pattern, 1, $close - 1);
			$flags = substr($this->pattern, $close + 1);
			
			return "new Pattern(/{$body}/{$flags})";
		}
	}