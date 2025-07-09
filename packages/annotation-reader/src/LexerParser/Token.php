<?php
	
	namespace Quellabs\AnnotationReader\LexerParser;
	
	use Quellabs\AnnotationReader\Annotation\BasicEnum;
	
	/**
	 * Class Token
	 * @package Quellabs\AnnotationsReader
	 */
	class Token extends BasicEnum {
		const None = 0;
		const Eof = 1;
		const Annotation = 2;
		const Comma = 3;
		const Dot = 4;
		const ParenthesesOpen = 5;
		const ParenthesesClose = 6;
		const CurlyBraceOpen = 7;
		const CurlyBraceClose = 8;
		const Equals = 9;
		const LargerThan = 10;
		const SmallerThan = 11;
		const String = 12;
		const Number = 13;
		const Parameter = 14;
		const True = 15;
		const False = 16;
		const BracketOpen = 17;
		const BracketClose = 18;
		const Plus = 19;
		const Minus = 20;
		const Underscore = 21;
		const Star = 22;
		const Variable = 23;
		const Colon = 24;
		const Semicolon = 25;
		const Slash = 26;
		const Backslash = 27;
		const Pipe = 28;
		const Percentage = 29;
		const Hash = 30;
		const Ampersand = 31;
		const Hat = 32;
		const Copyright = 33;
		const Pound = 34;
		const Euro = 35;
		const Exclamation = 36;
		const Question = 37;
		const Equal = 38;
		const Unequal = 39;
		const LargerThanOrEqualTo = 40;
		const SmallerThanOrEqualTo = 41;
		const LogicalAnd = 42;
		const LogicalOr = 43;
		const BinaryShiftLeft = 44;
		const BinaryShiftRight = 45;
		const Arrow = 46;
		const Dollar = 47;
		const DoubleColon = 48;
		
		protected string|int $type;
		protected mixed $value;
		
		/**
		 * Token constructor.
		 * @param int|string $type
		 * @param mixed|null $value
		 */
		public function __construct(int|string $type = Token::None, mixed $value = null) {
			$this->type = $type;
			$this->value = $value;
		}
		
		/**
		 * Returns the Token type
		 * @return int|string
		 */
		public function getType(): int|string {
			return $this->type;
		}
		
		/**
		 * Returns the (optional) value or null if there none
		 * @return mixed
		 */
		public function getValue(): mixed {
			return $this->value;
		}
	}