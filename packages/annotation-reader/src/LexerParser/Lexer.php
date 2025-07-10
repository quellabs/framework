<?php
	
	namespace Quellabs\AnnotationReader\LexerParser;
	
	use Quellabs\AnnotationReader\Exception\LexerException;
	
	/**
	 * Simple lexer to dissect doc blocks
	 * @package Quellabs\AnnotationsReader
	 */
	class Lexer {
		protected string $string;
		protected int $pos;
		protected int $length;
		protected array $tokens;
		protected array $single_tokens;
		protected array $two_char_tokens;
		protected Token $lookahead;
		protected bool $annotation_mode = false;
		
		/**
		 * Lexer constructor.
		 * @param string $string
		 * @throws LexerException
		 */
		public function __construct(string $string) {
			$this->string = $string;
			$this->pos = 0;
			$this->length = strlen($string);
			
			$this->single_tokens = [
				Token::Dot              => '.',
				Token::Comma            => ',',
				Token::Equals           => '=',
				Token::LargerThan       => '>',
				Token::SmallerThan      => '<',
				Token::ParenthesesOpen  => '(',
				Token::ParenthesesClose => ')',
				Token::CurlyBraceOpen   => '{',
				Token::CurlyBraceClose  => '}',
				Token::BracketOpen      => '[',
				Token::BracketClose     => ']',
				Token::Plus             => '+',
				Token::Minus            => '-',
				Token::Underscore       => '_',
				Token::Star             => '*',
				Token::Colon            => ':',
				Token::Semicolon        => ';',
				Token::Slash            => '/',
				Token::Backslash        => '\\',
				Token::Pipe             => '|',
				Token::Percentage       => '%',
				Token::Hash             => '#',
				Token::Ampersand        => '&',
				Token::Hat              => '^',
				Token::Copyright        => '©',
				Token::Pound            => '£',
				Token::Euro             => '€',
				Token::Exclamation      => '!',
				Token::Question         => '?',
				Token::Dollar           => '$',
				Token::Annotation       => '@',
			];
			
			$this->two_char_tokens = [
				Token::Equal                => '==',
				Token::Unequal              => '!=',
				Token::LargerThanOrEqualTo  => '>=',
				Token::SmallerThanOrEqualTo => '<=',
				Token::LogicalAnd           => '&&',
				Token::LogicalOr            => '||',
				Token::BinaryShiftLeft      => '<<',
				Token::BinaryShiftRight     => '>>',
				Token::Arrow                => '->',
				Token::DoubleColon          => '::',
			];
			
			$this->lookahead = $this->nextToken();
		}
		
		/**
		 * Match the next token
		 * @param int $token
		 * @return Token
		 * @throws LexerException
		 */
		public function match(int $token): Token {
			if ($this->lookahead->getType() == $token) {
				$currentToken = $this->lookahead;
				$this->lookahead = $this->nextToken();
				return $currentToken;
			}
			
			throw new LexerException("Unexpected token");
		}
		
		/**
		 * Match the next token
		 * @param int $token
		 * @param Token|null $result
		 * @return bool
		 * @throws LexerException
		 */
		public function optionalMatch(int $token, Token &$result = null): bool {
			// Check if the current lookahead token matches the expected token type
			if ($this->lookahead->getType() == $token) {
				// Store the current token before advancing
				$currentToken = $this->lookahead;
				
				// Advance to the next token in the stream
				$this->lookahead = $this->nextToken();
				
				// Only assign the matched token to result if a reference parameter was provided
				// This handles the edge case where passing null by reference doesn't work as expected
				if (func_num_args() > 1) {
					$result = $currentToken;
				}
				
				// Return true to indicate successful match
				return true;
			}
			
			// Token didn't match, return false without consuming any tokens
			return false;
		}
		
		/**
		 * Returns the next token and advances the token counter
		 * @return Token
		 * @throws LexerException
		 */
		public function get(): Token {
			$currentToken = $this->lookahead;
			$this->lookahead = $this->nextToken();
			return $currentToken;
		}
		
		/**
		 * Returns the next token without advancing the token counter
		 * @return Token
		 */
		public function peek(): Token {
			return $this->lookahead;
		}
		
		/**
		 * Toggles between annotation parsing and content parsing modes.
		 * When enabled, the parser processes annotation syntax and metadata.
		 * When disabled, the parser processes the surrounding document content.
		 * @param bool $mode True
		 * @return void
		 */
		public function setAnnotationMode(bool $mode): void {
			$this->annotation_mode = $mode;
		}
		
		/**
		 * Find the next annotation in the string
		 * @return bool True if an annotation was found, false otherwise
		 */
		protected function findNextAnnotation(): bool {
			// Reset annotation mode
			$this->annotation_mode = false;
			
			// Find the next @ symbol
			while ($this->pos < $this->length) {
				if ($this->string[$this->pos] == '@') {
					// Found an annotation, set the mode and return true
					$this->annotation_mode = true;
					return true;
				}
				
				++$this->pos;
			}
			
			// No more annotations found
			return false;
		}
		
		/**
		 * Checks if we've reached the end of an annotation
		 * This happens when we encounter a newline that's not within a string or parentheses
		 * @return bool True if we've reached the end of an annotation
		 */
		protected function isEndOfAnnotation(): bool {
			// Return false if we're not in annotation mode
			if (!$this->annotation_mode) {
				return false;
			}
			
			// Check if the current character is a newline
			if ($this->pos < $this->length && $this->string[$this->pos] == "\n") {
				// Get the rest of the line
				$nextLinePos = $this->pos + 1;
				
				// Skip whitespace and * at the start of the next line
				while ($nextLinePos < $this->length && (
					$this->string[$nextLinePos] == ' ' ||
					$this->string[$nextLinePos] == "\t" ||
					$this->string[$nextLinePos] == '*'
				)) {
					$nextLinePos++;
				}
				
				// If the next non-whitespace character is not @, this is the end of the annotation
				if ($nextLinePos >= $this->length || $this->string[$nextLinePos] != '@') {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Advance $this->pos to the start of the next token
		 * This method skips over whitespace, newlines, and comment markers
		 * to position the cursor at the beginning of the next meaningful token
		 */
		protected function advance(): void {
			// Handle annotation mode first
			if (!$this->handleAnnotationMode()) {
				return; // We've reached the end of the string
			}
			
			// Process whitespace, comments, and find the next token
			$this->skipWhitespaceAndComments();
		}
		
		/**
		 * Handles annotation mode logic and positioning
		 *
		 * @return bool False if we've reached the end of string, true otherwise
		 */
		protected function handleAnnotationMode(): bool {
			// If we're not in annotation mode, try to find the next annotation
			if (!$this->annotation_mode) {
				// If we can't find another annotation, advance to the end of the string
				if (!$this->findNextAnnotation()) {
					$this->pos = $this->length;
					return false;
				}
			}
			
			// Check if we've reached the end of an annotation
			if ($this->isEndOfAnnotation()) {
				// Reset annotation_mode flag
				$this->annotation_mode = false;
				
				// Try to find the next annotation
				if (!$this->findNextAnnotation()) {
					$this->pos = $this->length;
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * Skips over whitespace, newlines, and comment markers
		 */
		protected function skipWhitespaceAndComments(): void {
			// Flag to track if we've just seen a newline and should check for a star
			$checkStar = false;
			
			while ($this->pos < $this->length) {
				if ($this->handleNewline($checkStar)) {
					continue;
				}
				
				if ($this->skipWhitespaceAndStars($checkStar)) {
					continue;
				}
				
				if ($this->skipDocBlockCommentMarkers()) {
					$checkStar = false;
					continue;
				}
				
				// If we reach this point, we've found a non-whitespace, non-comment character
				// This should be the start of a token, so exit the loop
				break;
			}
		}
		
		/**
		 * Handles newline characters and sets the checkStar flag
		 * @param bool &$checkStar Reference to the checkStar flag that tracks if we should look for "*" characters
		 * @return bool True if a newline was handled, false otherwise
		 */
		protected function handleNewline(bool &$checkStar): bool {
			// Check if the current character is a newline
			if ($this->string[$this->pos] == "\n") {
				// In docblock comments, each line often starts with a "*" after a newline
				// Set flag to true, so we can check for and skip these stars in the next iteration
				$checkStar = true;
				
				// Move the position cursor past the newline character
				++$this->pos;
				
				// Return true to indicate we found and processed a newline
				return true;
			}
			
			// Return false if the current character is not a newline
			// This allows the calling method to try other character types
			return false;
		}
		
		/**
		 * Skips whitespace characters and stars after newlines
		 * @param bool &$checkStar Reference to the checkStar flag
		 * @return bool True if whitespace or star was skipped, false otherwise
		 */
		protected function skipWhitespaceAndStars(bool &$checkStar): bool {
			// Check if current position is beyond string length
			if ($this->pos >= $this->length) {
				return false;
			}
			
			// Check for regular whitespace characters (space, newline, carriage return, tab)
			if (in_array($this->string[$this->pos], [" ", "\n", "\r", "\t"])) {
				++$this->pos;
				return true;
			}
			
			// Check for asterisk after newline when checkStar flag is set
			if ($checkStar && $this->string[$this->pos] == "*") {
				// Reset the checkStar flag since we've found and handled the star
				$checkStar = false;
				++$this->pos;
				return true;
			}
			
			// No whitespace or relevant star character found
			return false;
		}
		
		/**
		 * This method identifies and skips over the beginning of a docblock comment.
		 * It specifically looks for the pattern "/*" and variations with multiple asterisks.
		 * The method advances the position pointer past these markers so parsing can continue.
		 * @return bool True if comment markers were skipped, false otherwise
		 */
		protected function skipDocBlockCommentMarkers(): bool {
			// Check if the current character is a forward slash
			// This could be the start of a doc block comment "/*"
			if ($this->string[$this->pos] == '/') {
				// We found a slash, now look for the asterisk(s) that would make this a doc block
				// This loop checks for up to 2 consecutive asterisks following the slash
				// This handles both normal docblocks "/*" and JavaDoc-style "/**" comments
				for ($j = 0; $j < 2; ++$j) {
					// Check if there's another character after current position
					// and if that character is an asterisk
					if (($this->pos + 1 < $this->length) && $this->string[$this->pos + 1] == '*') {
						// Move past the asterisk
						++$this->pos;
					}
				}
				
				// Move past the last processed character (either the slash or last asterisk)
				// This ensures we're positioned at the start of the actual comment content
				++$this->pos;
				
				// Return true to indicate we found and processed a comment marker
				return true;
			}
			
			// Return false if the current character is not a forward slash
			// This means no comment marker was found at the current position
			return false;
		}
		
		/**
		 * Fetches a number from the datastream and returns it.
		 * Parses both integer and floating point numbers from the current position.
		 * Advances the position to the character after the number.
		 * @return float|int The parsed number as an integer or float
		 * @throws LexerException When the number contains multiple decimal points
		 */
		protected function fetchNumber(): float|int {
			// Initialize an empty string to build the number
			$string = "";
			
			// Continue reading characters as long as they're digits or a decimal point
			// This collects the entire number including any decimal component
			while (($this->pos < $this->length) && (ctype_digit($this->string[$this->pos]) || $this->string[$this->pos] == '.')) {
				// Add current character to string and advance position
				$string .= $this->string[$this->pos++];
			}
			
			// Determine if the number is an integer or float based on decimal points
			// Count the number of decimal points in the string
			if (substr_count($string, ".") == 0) {
				// No decimal points means it's an integer
				return intval($string);
			}
			
			// One decimal point means it's a floating point number
			if (substr_count($string, ".") == 1) {
				return floatval($string);
			}
			
			// Multiple decimal points means the number is malformed
			// This is not a valid number in most programming languages
			throw new LexerException("Malformed floating point number");
		}
		
		/**
		 * Fetches the next token
		 * @return Token
		 * @throws LexerException
		 */
		protected function nextToken(): Token {
			// advance pos to next token
			$this->advance();
			
			// end of file
			if ($this->pos == $this->length) {
				return new Token(Token::Eof);
			}
			
			// Try each token type checker in order
			$tokenCheckers = [
				'checkAnnotationToken',
				'checkTwoCharToken',
				'checkSingleCharToken',
				'checkNumberToken',
				'checkStringToken',
				'checkIdentifierToken'
			];
			
			foreach ($tokenCheckers as $checker) {
				if ($token = $this->$checker()) {
					return $token;
				}
			}
			
			// error - unknown token
			return new Token(Token::None, $this->string[$this->pos++]);
		}
		
		/**
		 * Check for two-character tokens (e.g., ==, !=, >=, <=, &&, ||, etc.)
		 * @return Token|null
		 */
		protected function checkTwoCharToken(): ?Token {
			// First verify that we have at least 2 characters remaining in the input
			// to avoid out-of-bounds array access
			if ($this->pos + 1 < $this->length) {
				// Iterate through all defined two-character tokens
				foreach ($this->two_char_tokens as $tctk => $tctv) {
					// Check if the current and next characters match a two-character token
					// $tctv[0] is the first character of the token
					// $tctv[1] is the second character of the token
					if (($this->string[$this->pos] == $tctv[0]) && ($this->string[$this->pos + 1] == $tctv[1])) {
						// Move position ahead by 2 to skip both characters of the token
						$this->pos += 2;
						
						// Return a token with the appropriate type (key from two_char_tokens array)
						return new Token($tctk);
					}
				}
			}
			
			// Return null if no two-character token was found at current position
			return null;
		}
		
		/**
		 * Check for number tokens (integers or floating point)
		 * @return Token|null
		 * @throws LexerException When encountering a malformed number (e.g., multiple decimal points)
		 */
		protected function checkNumberToken(): ?Token {
			// Check if the current character is a digit (0-9)
			if (ctype_digit($this->string[$this->pos])) {
				// Delegate to the fetchNumber method to parse the complete number
				// This handles both integers and floating point numbers
				// The position will be advanced in the fetchNumber method
				return new Token(Token::Number, $this->fetchNumber());
			}
			
			// Return null if no number token was found at current position
			return null;
		}
		
		/**
		 * Check for single character tokens (punctuation and operators)
		 * @return Token|null
		 */
		protected function checkSingleCharToken(): ?Token {
			// Check if the current character matches any of the defined single character tokens
			// array_search returns the key (token type) if found, or false if not found
			if (($index = array_search($this->string[$this->pos], $this->single_tokens))) {
				// Increment position to move past the single character token
				++$this->pos;
				
				// Return a token with the appropriate type (index from single_tokens array)
				// No value is needed since the token type fully identifies the character
				return new Token($index);
			}
			
			// Return null if no single character token was found at current position
			return null;
		}
		
		/**
		 * Check for string tokens (single or double quotes)
		 * @return Token|null
		 * @throws LexerException When string is not properly terminated or contains newlines
		 */
		protected function checkStringToken(): ?Token {
			// Check if current character is either a single or double quote
			if ($this->string[$this->pos] == '"' || $this->string[$this->pos] == "'") {
				// Store the specific quote character (either ' or ") to match the string ending
				$quote = $this->string[$this->pos];
				
				// Initialize an empty string to build the string content
				$string = "";
				
				// Pre-increment position to skip the opening quote
				++$this->pos;
				
				// Continue until matching closing quote
				while ($this->pos < $this->length) {
					// If we encounter a newline character inside the string
					if ($this->string[$this->pos] == "\n") {
						// Strings cannot contain newlines - throw exception
						throw new LexerException("Unexpected newline in string");
					}
					
					// Check for escape sequences
					if ($this->string[$this->pos] == '\\' && $this->pos + 1 < $this->length) {
						// Move to the escaped character
						++$this->pos;
						
						// Handle special escape sequences
						switch ($this->string[$this->pos]) {
							case 'n': $string .= "\n"; break;
							case 'r': $string .= "\r"; break;
							case 't': $string .= "\t"; break;
							case '"': $string .= '"'; break;
							case "'": $string .= "'"; break;
							default: $string .= $this->string[$this->pos]; break;
						}
						++$this->pos;
						continue;
					}
					
					// Check if we've reached the end of the string
					if ($this->string[$this->pos] == $quote) {
						++$this->pos; // Move past the closing quote
						return new Token(Token::String, $string);
					}
					
					// Add current character to the string content and advance
					$string .= $this->string[$this->pos];
					++$this->pos;
				}
				
				// If we reached the end of input before finding the closing quote
				throw new LexerException("Unexpected end of data");
			}
			
			// Return null if no string token was found at current position
			return null;
		}
		
		/**
		 * Check for annotation tokens (starting with @)
		 * @return Token|null
		 */
		protected function checkAnnotationToken(): ?Token {
			// Check if current character is the @ symbol that signifies an annotation
			if ($this->string[$this->pos] == '@') {
				// Consume the @ symbol and advance position
				++$this->pos;
				
				// Initialize an empty string to build the annotation name
				$string = "";
				
				// Continue reading characters as long as they're alphanumeric or backslash
				// Backslash is included to support namespaced annotations (e.g. @Namespace\Annotation)
				while (($this->pos < $this->length) && (ctype_alnum($this->string[$this->pos]) || ($this->string[$this->pos] == '\\'))) {
					// Add current character to string and advance position
					$string .= $this->string[$this->pos++];
				}
				
				// Return an Annotation token with the extracted annotation name
				// Note: The @ symbol is not included in the token value
				return new Token(Token::Annotation, $string);
			}
			
			// Return null if no annotation token was found at current position
			return null;
		}
		
		/**
		 * Check for identifier tokens (parameters, true, false)
		 * @return Token|null
		 */
		protected function checkIdentifierToken(): ?Token {
			// Check if the current character is alphabetic (a-z, A-Z)
			if (ctype_alpha($this->string[$this->pos])) {
				// Initialize an empty string to build the identifier
				$string = "";
				
				// Continue reading characters as long as they're alphanumeric or allowed special chars
				// Allowed characters include: letters, numbers, underscore, and hyphen
				while (($this->pos < $this->length) && (ctype_alnum($this->string[$this->pos]) || in_array($this->string[$this->pos], ['_', '-']))) {
					// Add current character to string and advance position
					$string .= $this->string[$this->pos++];
				}
				
				// Special handling for boolean literals
				// Case-insensitive comparison for "true" and "false"
				if (strcasecmp($string, "true") == 0) {
					return new Token(Token::True);
				} elseif (strcasecmp($string, "false") == 0) {
					return new Token(Token::False);
				} else {
					return new Token(Token::Parameter, $string);
				}
			}
			
			// Return null if no identifier token was found at current position
			return null;
		}
		
		/**
		 * Save the state of the lexer
		 * @return LexerState
		 */
		public function saveState(): LexerState {
			return new LexerState(
				$this->pos,
				$this->lookahead,
			);
		}
		
		/**
		 * Restore the state of the lexer
		 * @param LexerState $state
		 * @return void
		 */
		public function restoreState(LexerState $state): void {
			$this->pos = $state->getPos();
			$this->lookahead = $state->getLookahead();
		}
	}