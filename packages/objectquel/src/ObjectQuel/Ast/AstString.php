<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * Class AstString
	 * Represents a string constant in the Abstract Syntax Tree (AST).
	 */
	class AstString extends Ast {

		/**
		 * The string value represented by this AST node.
		 * @var string
		 */
		protected string $string;
		protected string $enclosingChar;

		/** @var int[] Byte offsets in $string of unescaped * and ? wildcards */
		protected array $wildcardOffsets;

		/** @var CharacterClass[] Unescaped [...] character classes in $string */
		protected array $characterClasses;

		/** Pattern syntax error in the literal, or null when it has none */
		protected ?string $patternError;

		/**
		 * AstString constructor.
		 * Initializes the node with a string value.
		 * @param string $string The string value to store.
		 * @param string $enclosingChar The quote char
		 * @param int[] $wildcardOffsets Byte offsets of unescaped * and ? wildcards in $string
		 * @param CharacterClass[] $characterClasses Unescaped [...] character classes in $string
		 * @param string|null $patternError Pattern syntax error, or null when it has none
		 */
		public function __construct(
			string $string,
			string $enclosingChar,
			array $wildcardOffsets = [],
			array $characterClasses = [],
			?string $patternError = null
		) {
			$this->string = $string;
			$this->enclosingChar = $enclosingChar;
			$this->wildcardOffsets = $wildcardOffsets;
			$this->characterClasses = $characterClasses;
			$this->patternError = $patternError;
		}

		/**
		 * Retrieves the string value stored in this AST node.
		 * @return string The stored string value.
		 */
		public function getValue(): string {
			return $this->string;
		}

		/**
		 * Retrieves the quote char
		 * @return string The stored string value.
		 */
		public function getEnclosingChar(): string {
			return $this->enclosingChar;
		}

		/**
		 * Returns the byte offsets of unescaped * and ? wildcards in the value.
		 * @return int[]
		 */
		public function getWildcardOffsets(): array {
			return $this->wildcardOffsets;
		}

		/**
		 * Returns the unescaped [...] character classes in the value.
		 * @return CharacterClass[]
		 */
		public function getCharacterClasses(): array {
			return $this->characterClasses;
		}

		/**
		 * Returns the pattern syntax error in the literal, or null when it has none.
		 * @return string|null
		 */
		public function getPatternError(): ?string {
			return $this->patternError;
		}

		/**
		 * Returns true when the value contains an unescaped wildcard or character class, making a comparison a pattern match.
		 * @return bool
		 */
		public function isPattern(): bool {
			return $this->wildcardOffsets !== [] || $this->characterClasses !== [];
		}

		/**
		 * Returns true when the value contains at least one unescaped character class.
		 * @return bool
		 */
		public function hasCharacterClasses(): bool {
			return $this->characterClasses !== [];
		}

		/**
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return "string";
		}

		/**
		 * Returns a deep clone of this node.
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static(
				$this->getValue(),
				$this->getEnclosingChar(),
				$this->getWildcardOffsets(),
				$this->getCharacterClasses(),
				$this->getPatternError()
			);
		}
	}
