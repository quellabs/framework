<?php
	
	namespace Quellabs\AnnotationReader\LexerParser;
	
	/**
	 * LexerState class
	 */
	class LexerState {
		
		/** @var int Current position in the input stream */
		private int $pos;
		
		/** @var Token Lookahead token for predictive parsing */
		private Token $lookahead;
		
		/** @var bool Whether the lexer was in annotation mode when the state was saved */
		private bool $annotation_mode;
		
		/** @var int Parenthesis nesting depth when the state was saved */
		private int $parenthesis_depth;
		
		/**
		 * Constructs a new LexerState with the given parameters
		 * @param int $pos Current position in the input
		 * @param Token $lookahead Lookahead token for predictive parsing
		 * @param bool $annotation_mode Whether the lexer was in annotation mode
		 * @param int $parenthesis_depth Parenthesis nesting depth
		 */
		public function __construct(
			int $pos,
			Token $lookahead,
			bool $annotation_mode,
			int $parenthesis_depth,
		) {
			$this->pos = $pos;
			$this->lookahead = $lookahead;
			$this->annotation_mode = $annotation_mode;
			$this->parenthesis_depth = $parenthesis_depth;
		}
		
		/**
		 * Gets the current position in the input stream
		 * @return int Current position
		 */
		public function getPos(): int {
			return $this->pos;
		}
		
		/**
		 * Gets the lookahead token
		 * Used for predictive parsing and decision making
		 * @return Token The lookahead token
		 */
		public function getLookahead(): Token {
			return $this->lookahead;
		}
		
		/**
		 * Gets whether the lexer was in annotation mode when the state was saved
		 * @return bool Annotation mode flag
		 */
		public function getAnnotationMode(): bool {
			return $this->annotation_mode;
		}
		
		/**
		 * Gets the parenthesis nesting depth when the state was saved
		 * @return int Parenthesis depth
		 */
		public function getParenthesisDepth(): int {
			return $this->parenthesis_depth;
		}
	}