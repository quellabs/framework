<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\CharacterClass;

	/**
	 * Builds a string literal's value while recording its pattern syntax: unescaped * and ? wildcards and [...] classes.
	 */
	final class StringPatternScanner {

		/** Range endpoint groups; both endpoints of a range must lie in the same group */
		private const array RANGE_GROUPS = ['0123456789', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'];

		/** Decoded string value */
		private string $value = '';

		/** @var list<int> Byte offsets of unescaped * and ? wildcards */
		private array $wildcardOffsets = [];

		/** @var list<CharacterClass> Character classes in order of appearance */
		private array $characterClasses = [];

		/** First pattern syntax error, or null when the pattern is valid */
		private ?string $patternError = null;

		/** Byte offset of the unescaped [ of the class being scanned, or null outside a class */
		private ?int $classStart = null;

		/**
		 * Appends a character produced by an escape sequence; it never has pattern meaning.
		 * @param string $character Decoded character
		 * @return void
		 */
		public function appendEscaped(string $character): void {
			$this->value .= $character;
		}

		/**
		 * Appends one unescaped byte, recording wildcards and class boundaries.
		 * @param string $byte The byte as written in the query
		 * @return void
		 */
		public function appendRaw(string $byte): void {
			if ($this->classStart !== null) {
				if ($byte === ']') {
					$this->closeClass($this->classStart);
				}

				$this->value .= $byte;
				return;
			}

			if ($byte === '*' || $byte === '?') {
				$this->wildcardOffsets[] = strlen($this->value);
			} elseif ($byte === '[') {
				$this->classStart = strlen($this->value);
			}

			$this->value .= $byte;
		}

		/**
		 * Ends scanning; an unclosed class becomes a pattern error.
		 * @return void
		 */
		public function finish(): void {
			if ($this->classStart !== null) {
				$this->recordError('Unterminated character class');
				$this->classStart = null;
			}
		}

		/**
		 * Returns the decoded string value.
		 * @return string
		 */
		public function getValue(): string {
			return $this->value;
		}

		/**
		 * Returns the byte offsets of unescaped * and ? wildcards.
		 * @return list<int>
		 */
		public function getWildcardOffsets(): array {
			return $this->wildcardOffsets;
		}

		/**
		 * Returns the character classes in order of appearance.
		 * @return list<CharacterClass>
		 */
		public function getCharacterClasses(): array {
			return $this->characterClasses;
		}

		/**
		 * Returns the first pattern syntax error, or null when the pattern is valid.
		 * @return string|null
		 */
		public function getPatternError(): ?string {
			return $this->patternError;
		}

		/**
		 * Parses the members of the class being closed and records it; called before the ] is appended.
		 * @param int $start Byte offset of the class's opening [
		 * @return void
		 */
		private function closeClass(int $start): void {
			$this->classStart = null;

			$characters = $this->parseMembers(substr($this->value, $start + 1));

			if ($characters !== null) {
				$this->characterClasses[] = new CharacterClass($start, strlen($this->value) + 1, $characters);
			}
		}

		/**
		 * Expands class members into distinct characters; a - between two members forms a range, a leading or trailing - is literal.
		 * @param string $members Text between [ and ]
		 * @return list<string>|null Null when the members are invalid
		 */
		private function parseMembers(string $members): ?array {
			$characters = preg_split('//u', $members, -1, PREG_SPLIT_NO_EMPTY);

			if ($characters === false) {
				$this->recordError('Invalid UTF-8 in character class');
				return null;
			}

			if ($characters === []) {
				$this->recordError('Empty character class');
				return null;
			}

			$result = [];
			$count = count($characters);

			for ($i = 0; $i < $count; $i++) {
				if ($i + 2 < $count && $characters[$i + 1] === '-') {
					$range = $this->expandRange($characters[$i], $characters[$i + 2]);

					if ($range === null) {
						return null;
					}

					array_push($result, ...$range);
					$i += 2;
					continue;
				}

				// A - that is neither first nor last and did not form a range follows a range, as in [a-c-e]
				if ($characters[$i] === '-' && $i > 0 && $i < $count - 1) {
					$this->recordError("Ambiguous '-' in character class; put a literal - first or last");
					return null;
				}

				$result[] = $characters[$i];
			}

			return array_values(array_unique($result));
		}

		/**
		 * Expands a range whose endpoints lie in the same group of 0-9, A-Z or a-z.
		 * @param string $from Range start
		 * @param string $to Range end
		 * @return list<string>|null Null when the range is invalid
		 */
		private function expandRange(string $from, string $to): ?array {
			foreach (self::RANGE_GROUPS as $group) {
				$fromPosition = strlen($from) === 1 ? strpos($group, $from) : false;
				$toPosition = strlen($to) === 1 ? strpos($group, $to) : false;

				if ($fromPosition !== false && $toPosition !== false && $fromPosition <= $toPosition) {
					return str_split(substr($group, $fromPosition, $toPosition - $fromPosition + 1));
				}
			}

			$this->recordError("Invalid range '{$from}-{$to}' in character class; ranges must ascend within 0-9, A-Z or a-z");
			return null;
		}

		/**
		 * Records a pattern error, keeping the first one.
		 * @param string $message Error description
		 * @return void
		 */
		private function recordError(string $message): void {
			$this->patternError ??= $message;
		}
	}
