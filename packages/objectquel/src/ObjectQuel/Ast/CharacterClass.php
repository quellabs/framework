<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A [...] character class in a string literal pattern: its byte span in the value and the characters it matches.
	 */
	final class CharacterClass {

		/** Byte offset of the opening [ in the string value */
		private int $start;

		/** Byte offset just past the closing ] in the string value */
		private int $end;

		/** @var list<string> Distinct characters the class matches, with ranges expanded */
		private array $characters;

		/**
		 * CharacterClass constructor.
		 * @param int $start Byte offset of the opening [
		 * @param int $end Byte offset just past the closing ]
		 * @param list<string> $characters Distinct characters the class matches
		 */
		public function __construct(int $start, int $end, array $characters) {
			$this->start = $start;
			$this->end = $end;
			$this->characters = $characters;
		}

		/**
		 * Returns the byte offset of the opening [.
		 * @return int
		 */
		public function getStart(): int {
			return $this->start;
		}

		/**
		 * Returns the byte offset just past the closing ].
		 * @return int
		 */
		public function getEnd(): int {
			return $this->end;
		}

		/**
		 * Returns the distinct characters the class matches.
		 * @return list<string>
		 */
		public function getCharacters(): array {
			return $this->characters;
		}
	}
