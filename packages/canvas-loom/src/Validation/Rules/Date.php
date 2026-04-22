<?php
	
	namespace Quellabs\Canvas\Loom\Validation\Rules;
	
	/**
	 * Fails if the value is not a recognisable date string.
	 * Supports a wide range of common date and datetime formats.
	 * Empty values pass — combine with NotBlank if the field is required.
	 */
	class Date extends RuleBase {
		
		/**
		 * Detects whether $value matches any known date/time pattern.
		 * @param string $date
		 * @return bool
		 */
		protected function isRecognisedDate(string $date): bool {
			$patterns = [
				'/\b\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}.\d{3,8}Z\b/',
				'/\b\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2]\d|3[0-1])\b/',
				'/\b\d{4}-(0[1-9]|[1-2]\d|3[0-1])-(0[1-9]|1[0-2])\b/',
				'/\b([1-9]|[1-2]\d|3[0-1])-([1-9]|1[0-2])-\d{4}\b/',
				'/\b(0[1-9]|[1-2]\d|3[0-1])-(0[1-9]|1[0-2])-\d{4}\b/',
				'/\b(0[1-9]|1[0-2])-(0[1-9]|[1-2]\d|3[0-1])-\d{4}\b/',
				'/\b\d{4}\/(0[1-9]|[1-2]\d|3[0-1])\/(0[1-9]|1[0-2])\b/',
				'/\b\d{4}\/(0[1-9]|1[0-2])\/(0[1-9]|[1-2]\d|3[0-1])\b/',
				'/\b(0[1-9]|[1-2]\d|3[0-1])\/(0[1-9]|1[0-2])\/\d{4}\b/',
				'/\b(0[1-9]|1[0-2])\/(0[1-9]|[1-2]\d|3[0-1])\/\d{4}\b/',
				'/\b\d{4}\.(0[1-9]|1[0-2])\.(0[1-9]|[1-2]\d|3[0-1])\b/',
				'/\b\d{4}\.(0[1-9]|[1-2]\d|3[0-1])\.(0[1-9]|1[0-2])\b/',
				'/\b(0[1-9]|[1-2]\d|3[0-1])\.(0[1-9]|1[0-2])\.\d{4}\b/',
				'/\b(0[1-9]|1[0-2])\.(0[1-9]|[1-2]\d|3[0-1])\.\d{4}\b/',
				'/\b(?:2[0-3]|[01]\d):[0-5]\d(:[0-5]\d)\b/',
				'/\b(?:2[0-3]|[01]\d):[0-5]\d\b/',
			];
			
			foreach ($patterns as $pattern) {
				if (preg_match($pattern, $date)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * @inheritDoc
		 */
		public function validate(mixed $value): bool {
			if ($value === null || $value === '') {
				return true;
			}
			
			return $this->isRecognisedDate((string)$value);
		}
		
		/**
		 * @inheritDoc
		 */
		public function getError(): string {
			return $this->message ?? 'This value is not a valid date.';
		}
	}