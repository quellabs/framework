<?php
	
	namespace Quellabs\AnnotationReader\LexerParser;
	
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\AnnotationReader\Exception\LexerException;
	use Quellabs\AnnotationReader\Exception\ParserException;
	
	class Parser {

		// Default keys used throughout the parser
		private const DEFAULT_VALUE_KEY = 'value';
		private const SWAGGER_PREFIX = 'OA\\';
		
		/** @var Lexer The lexer instance for tokenizing input */
		private Lexer $lexer;
		
		/** @var array List of annotation names to ignore during parsing */
		private array $ignore_annotations;
		
		/** @var array Fast lookup set for ignored annotations (using array keys for O(1) lookup) */
		private array $ignore_annotations_set;
		
		/**
		 * @var array<string, array{segments: string[], fqcn: string, lastPart: string, namespace: string}>
		 * Preprocessed import data for faster class resolution
		 */
		private array $preprocessed_imports = [];
		
		/**
		 * @var array<string, string[]>
		 * Reverse mapping from namespace segments to full namespaces for quick lookups
		 */
		private array $namespace_map = [];
		
		/**
		 * @var array<string, string> Map of aliases to fully qualified class names
		 */
		private array $imports = [];
		
		/**
		 * @var array Cache for resolved class references to avoid repeated resolution
		 */
		private array $classReferenceCache = [];
		
		/**
		 * @var array<string, mixed> Configuration array for resolving configuration placeholders
		 */
		private array $configuration;
		
		/**
		 * @var string|null The current namespace context for resolving relative class names
		 */
		private ?string $currentNamespace = null;

		/**
		 * Parser constructor.
		 * @param Lexer $lexer The lexer instance for tokenizing input
		 * @param array<string, mixed> $configuration Configuration array for placeholder resolution
		 * @param array<string, string> $imports Optional map of aliases to fully qualified class names
		 * @param string|null $currentNamespace Optional namespace of the file we are currently reading
		 */
		public function __construct(Lexer $lexer, array $configuration=[], array $imports = [], ?string $currentNamespace=null) {
			// Store the lexer instance for token processing
			$this->lexer = $lexer;
			
			// Store the namespace if any
			$this->currentNamespace = $currentNamespace;
			
			// Store configuration for resolving ${config.key} placeholders
			$this->configuration = $configuration;
			
			// Store import mappings for class resolution
			$this->imports = $imports;
			
			// Define standard PHP doc annotations that should be ignored during parsing
			$this->ignore_annotations = [
				'param',      // Method parameter documentation
				'return',     // Return type documentation
				'var',        // Variable type documentation
				'type',       // Type hint documentation
				'throws',     // Exception documentation
				'todo',       // TODO comments
				'fixme',      // FIXME comments
				'author',     // Author information
				'copyright',  // Copyright information
				'license',    // License information
				'package',    // Package information
				'template',   // Template documentation
				'url',        // URL references
				'note',       // General notes
				'deprecated', // Deprecation notices
				'since',      // Version since information
				'see',        // See also references
				'example',    // Example code
				'inheritdoc', // Inherit documentation
				'internal',   // Internal use only
				'api',        // API documentation
				'version',    // Version information
				'category'    // Category classification
			];
			
			// Create a fast lookup set from ignored annotations (O(1) instead of O(n) lookups)
			$this->ignore_annotations_set = array_flip($this->ignore_annotations);
			
			// Preprocess imports for faster class resolution during parsing
			$this->preprocessImports();
		}
		
		/**
		 * Parse the Docblock
		 * @return AnnotationCollection
		 * @throws LexerException|ParserException
		 */
		public function parse(): AnnotationCollection {
			try {
				$result = [];
				$token = $this->lexer->get();
				
				while ($token->getType() !== Token::Eof) {
					// Skip non-annotation tokens
					if ($token->getType() !== Token::Annotation) {
						$token = $this->lexer->get();
						continue;
					}
					
					// Skip ignored annotations
					$value = $token->getValue();
					
					if (isset($this->ignore_annotations_set[$value])) {
						$token = $this->lexer->get();
						continue;
					}
					
					// Skip swagger docs
					if (str_starts_with($value, self::SWAGGER_PREFIX)) {
						$token = $this->lexer->get();
						continue;
					}
					
					// Parse the annotation
					$annotation = $this->parseAnnotation($token);
					
					// Add the annotation to the result
					$result[] = $annotation;
					
					// Get the next token
					$token = $this->lexer->get();
				}
				
				return new AnnotationCollection($result);
			} catch (\ReflectionException $e) {
				throw new ParserException("Reflection error: {$e->getMessage()}", $e->getCode(), $e);
			}
		}

		/**
		 * Preprocess imports for faster class resolution
		 */
		private function preprocessImports(): void {
			foreach ($this->imports as $alias => $fqcn) {
				// Split each import into segments for easier matching
				$segments = explode('\\', $fqcn);
				
				$this->preprocessed_imports[$alias] = [
					'segments'  => $segments,
					'fqcn'      => $fqcn,
					'lastPart'  => end($segments),
					'namespace' => implode('\\', array_slice($segments, 0, -1))
				];
				
				// Create reverse mapping for namespace lookups
				foreach ($segments as $index => $segment) {
					$partialNamespace = implode('\\', array_slice($segments, 0, $index + 1));
					$this->namespace_map[$segment][] = $partialNamespace;
				}
			}
		}
		
		/**
		 * Parses a configuration key in the format {parameter.parameter.parameter}
		 * converting it into a dot-notation string like "parameter.parameter.parameter"
		 * @return string The parsed configuration key in dot notation
		 * @throws LexerException
		 */
		private function parseConfigurationKey(): string {
			// Match the opening curly brace that starts the configuration key
			$this->lexer->match(Token::CurlyBraceOpen);
			
			// Initialize an empty string to build the configuration key
			$key = "";
			
			do {
				// If this isn't the first parameter, add a dot separator
				if (!empty($key)) {
					$key .= ".";
				}
				
				// Match and get the next parameter token in the sequence
				$parameter = $this->lexer->match(Token::Parameter);
				
				// Append the parameter value to our building key
				$key .= $parameter->getValue();
			} while ($this->lexer->optionalMatch(Token::Dot)); // Continue if there's a dot, indicating more parameters
			
			// Match the closing curly brace that ends the configuration key
			$this->lexer->match(Token::CurlyBraceClose);
			
			// Return the fully constructed dot-notation configuration key
			return $key;
		}
		
		/**
		 * Parse a class constant reference (e.g., ClassName::class)
		 * @return string The fully qualified class name
		 * @throws LexerException|ParserException
		 */
		private function parseClassConstant(): string {
			$className = '';
			
			// Build the class name by consuming parameter tokens separated by backslashes
			do {
				if (!empty($className)) {
					$className .= '\\';
				}
				
				$token = $this->lexer->match(Token::Parameter);
				$className .= $token->getValue();
			} while ($this->lexer->optionalMatch(Token::Backslash));
			
			// Match the double colon
			$this->lexer->match(Token::DoubleColon);
			
			// Match the 'class' keyword
			$classToken = $this->lexer->match(Token::Parameter);
			
			if ($classToken->getValue() !== 'class') {
				throw new ParserException("Expected 'class' after '::', got: " . $classToken->getValue());
			}
			
			// Resolve the class name using existing resolution logic
			return $this->resolveClassName($className);
		}
		
		/**
		 * Parses a value from the token stream based on its type
		 * @param Token $token The token to parse
		 * @return mixed The parsed value (array, string, number, boolean, or null)
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseValue(Token $token): mixed {
			// Handle a configuration string (e.g. ${config.cache.default_ttl})
			if ($this->lexer->optionalMatch(Token::Dollar)) {
				$configKey = $this->parseConfigurationKey();
				return $this->resolveNestedValue($configKey);
			}
			
			// Handle class constant reference (e.g., ClassName::class)
			if ($this->isClassConstant()) {
				return $this->parseClassConstant();
			}
			
			// Handle a JSON string
			if ($this->lexer->optionalMatch(Token::CurlyBraceOpen)) {
				$value = $this->parseAttributeList();
				$this->lexer->match(Token::CurlyBraceClose);
				return $value;
			}
			
			// Handle string or number literals
			if ($this->lexer->optionalMatch(Token::String, $token) || $this->lexer->optionalMatch(Token::Number, $token)) {
				// Return the actual value of the token
				return $token->getValue();
			}
			
			// Handle negative numbers (e.g., -10)
			if ($this->lexer->peek()->getType() == Token::Minus) {
				// Consume the minus token
				$this->lexer->match(Token::Minus);
				
				// Get the number token that follows the minus
				$token = $this->lexer->match(Token::Number);
				
				// Return the negative value
				return 0 - $token->getValue();
			}
			
			// Handle boolean true value
			if ($this->lexer->optionalMatch(Token::True)) {
				return true;
			}
			
			// Handle boolean false value
			if ($this->lexer->optionalMatch(Token::False)) {
				return false;
			}
			
			// Default case: return null if no other value type matches
			return null;
		}
		
		/**
		 * Check if the next tokens form a class constant reference (ClassName::class)
		 * @return bool True if the next tokens form a class constant reference, false otherwise
		 */
		private function isClassConstant(): bool {
			// Save the current lexer state so we can restore it after lookahead
			// This ensures the lexer position remains unchanged regardless of the outcome
			$currentState = $this->lexer->saveState();
			
			try {
				// First check: ensure we start with a Parameter token (class name component)
				// If not, this definitely isn't a class constant reference
				if ($this->lexer->peek()->getType() !== Token::Parameter) {
					return false;
				}
				
				// Consume the first parameter token (initial class name or namespace component)
				$this->lexer->match(Token::Parameter);
				
				// Handle namespace separators and additional namespace/class components
				// Loop continues as long as we find backslash tokens followed by parameter tokens
				while ($this->lexer->optionalMatch(Token::Backslash)) {
					// After a backslash, we must have another parameter token
					// If not, this isn't a valid class reference pattern
					if ($this->lexer->peek()->getType() !== Token::Parameter) {
						// Restore lexer state and return false for invalid pattern
						$this->lexer->restoreState($currentState);
						return false;
					}
					
					// Consume the parameter token after the backslash
					$this->lexer->match(Token::Parameter);
				}
				
				// After the class name (with optional namespace), check for double colon
				// The :: operator is required for accessing class constants
				if ($this->lexer->peek()->getType() !== Token::DoubleColon) {
					// No double colon found, restore state and return false
					$this->lexer->restoreState($currentState);
					return false;
				}
				
				// Consume the double colon token
				$this->lexer->match(Token::DoubleColon);
				
				// Final check: verify the next token is the 'class' keyword
				// This is what makes it specifically a class constant reference
				$nextToken = $this->lexer->peek();
				
				$isClassConstant =
					$nextToken->getType() === Token::Parameter &&
					$nextToken->getValue() === 'class';
				
				// Always restore the lexer to its original position
				// This method only performs lookahead and shouldn't consume tokens
				$this->lexer->restoreState($currentState);
				
				return $isClassConstant;
				
			} catch (LexerException $e) {
				// If any lexer error occurs during lookahead (e.g., unexpected end of input),
				// restore the lexer state and safely return false
				// This ensures the parser can continue processing from the original position
				$this->lexer->restoreState($currentState);
				return false;
			}
		}
		
		/**
		 * Helper for parseJson
		 * @return mixed
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseAttributeValue(): mixed {
			$parameterValue = new Token();
			
			if ($this->lexer->optionalMatch(Token::CurlyBraceOpen)) {
				$value = $this->parseAttributeList();
				$this->lexer->match(Token::CurlyBraceClose);
			} elseif ($this->lexer->optionalMatch(Token::String, $parameterValue) || $this->lexer->optionalMatch(Token::Number, $parameterValue)) {
				$value = $parameterValue->getValue();
			} elseif ($this->lexer->optionalMatch(Token::Minus)) {
				$token = $this->lexer->match(Token::Number);
				$value = 0 - $token->getValue();
			} elseif ($this->lexer->optionalMatch(Token::True)) {
				$value = true;
			} elseif ($this->lexer->optionalMatch(Token::False)) {
				$value = false;
			} else {
				$value = null;
			}
			
			return $value;
		}
		
		/**
		 * Parses an attribute list into an associative array
		 * Can handle annotations, key-value pairs (key=value), and simple value arrays
		 * @return array The parsed attributes as an associative or indexed array
		 * @throws LexerException If tokenization fails
		 * @throws ParserException If the attribute structure is invalid
		 * @throws \ReflectionException If annotation reflection fails
		 */
		private function parseAttributeList(): array {
			// Initialize an empty array to store the parsed attributes
			$attributes = [];
			
			do {
				// Declare variables before use
				$annotationToken = null;
				$attributeKey = null;
				
				// Handle annotation syntax (@AnnotationName)
				if ($this->lexer->optionalMatch(Token::Annotation, $annotationToken)) {
					// Parse the annotation
					$annotation = $this->parseAnnotation($annotationToken);
					
					// Store it using its class name as the key
					$attributes[get_class($annotation)] = $annotation;
					
					// Next loop iteration
					continue;
				}
				
				// Try to match a string token for the attribute key
				if (!$this->lexer->optionalMatch(Token::String, $attributeKey)) {
					// If not a string, try to match a number token
					if (!$this->lexer->optionalMatch(Token::Number, $attributeKey)) {
						// If neither string nor number, throw an exception
						throw new ParserException("Expected number or string, got " . $attributeKey->toString($attributeKey->getType()));
					}
				}
				
				// Check if this is a key-value pair (has an equals sign)
				if (!$this->lexer->optionalMatch(Token::Equals)) {
					// No equals sign found, treat as a simple array value
					$attributes[] = $attributeKey->getValue();
					continue;
				}
				
				// Parse the value part of the key-value pair
				$value = $this->parseAttributeValue();
				
				// Ensure the value is valid
				if ($value === null) {
					throw new ParserException("Invalid value type");
				}
				
				// Add the key-value pair to the attributes array
				$attributes[$attributeKey->getValue()] = $value;
			} while ($this->lexer->optionalMatch(Token::Comma)); // Continue if there's a comma, indicating more attributes
			
			// Return the complete attributes array
			return $attributes;
		}
		
		/**
		 * Parse a string of parameters
		 * The first parameter can be nameless and can be an annotation
		 * All subsequent parameters must be named
		 * @return array
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseParameters(): array {
			$parameters = [];
			$isFirstParameter = true;
			
			do {
				// The first parameter can be nameless. It will be stored under the key self::DEFAULT_VALUE_KEY
				if ($isFirstParameter) {
					$this->parseFirstParameter($parameters);
					$isFirstParameter = false;
					continue;
				}
				
				// All subsequent parameters must be named
				if (!$this->parseNamedParameter($parameters)) {
					throw new ParserException("All parameters after the first must be named (parameter=value)");
				}
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			return $parameters;
		}
		
		/**
		 * Check if the next tokens form a named parameter (parameter=value)
		 * @return bool
		 */
		private function isNamedParameter(): bool {
			$savedState = $this->lexer->saveState();
			
			try {
				// Check for pattern: Parameter followed by Equals
				return
					$this->lexer->optionalMatch(Token::Parameter) &&
					$this->lexer->optionalMatch(Token::Equals);
			} finally {
				// Always restore lexer state after lookahead
				$this->lexer->restoreState($savedState);
			}
		}
		
		/**
		 * Parse the first parameter which can be an annotation, named parameter, or unnamed value
		 * @param array &$parameters Reference to parameters array to modify
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseFirstParameter(array &$parameters): void {
			// Check if the next token is an annotation (e.g., @annotation)
			// Use peek() to look ahead without consuming the token
			if ($this->lexer->peek()->getType() === Token::Annotation) {
				// Consume the annotation token from the lexer
				$annotationToken = $this->lexer->match(Token::Annotation);
				
				// Parse the annotation into its structured form
				$annotation = $this->parseAnnotation($annotationToken);
				
				// Store annotation as the default/primary value since it's the first parameter
				$parameters[self::DEFAULT_VALUE_KEY] = $annotation;
				return;
			}
			
			// Check if the next parameter is a class
			// Handle class constant reference (e.g., ClassName::class)
			if ($this->isClassConstant()) {
				$parameters[self::DEFAULT_VALUE_KEY] = $this->parseClassConstant();
				return;
			}
			
			// Check if this looks like a named parameter (parameter=value format)
			if ($this->isNamedParameter()) {
				// Delegate to specialized named parameter parsing logic
				$this->parseNamedParameter($parameters);
				return;
			}
			
			// If it's neither annotation nor named parameter, treat as unnamed value
			// This handles cases like simple literals, expressions, or other value types
			$value = $this->parseValue(new Token());
			
			// Only store the value if parsing was successful (not null)
			// Null could indicate empty input or parsing failure
			if ($value !== null) {
				// Store as default value since this is an unnamed first parameter
				$parameters[self::DEFAULT_VALUE_KEY] = $value;
			}
		}
		
		/**
		 * Parse a named parameter and add it to the parameters array
		 * @param array &$parameters Reference to parameters array to modify
		 * @return bool True if successfully parsed as named parameter, false otherwise
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseNamedParameter(array &$parameters): bool {
			// Extract the parameter name/key token from the lexer
			$parameterKey = $this->lexer->match(Token::Parameter);
			
			// Match the equals sign
			$this->lexer->match(Token::Equals);
			
			// We found an equals sign, so now parse the value that comes after it
			// Pass a new Token instance as context for value parsing
			$value = $this->parseValue(new Token());
			
			// Validate that we successfully parsed a value
			if ($value === null) {
				// Throw exception if no valid value was found after the equals sign
				throw new ParserException("Expected valid value for parameter: " . $parameterKey->getValue());
			}
			
			// Store the parsed value using the parameter name as the key
			$parameters[$parameterKey->getValue()] = $value;
			
			// Return true to indicate successful parsing of a named parameter
			return true;
		}
		
		/**
		 * Parses an annotation
		 * @param Token $token
		 * @return object
		 * @throws LexerException
		 * @throws ParserException
		 * @throws \ReflectionException
		 */
		private function parseAnnotation(Token $token): object {
			// Fetch the annotation class name
			$value = $token->getValue();
			
			// Resolve the annotation class name using imports
			$tokenName = $this->resolveClassName($value);
			
			// Check if the class exists
			if (!class_exists($tokenName)) {
				throw new ParserException("Annotation class not found: {$tokenName}");
			}
			
			// Parse the parameters or use an empty array
			$parameters = [];
			
			if ($this->lexer->optionalMatch(Token::ParenthesesOpen)) {
				$parameters = $this->parseParameters();
				$this->lexer->match(Token::ParenthesesClose);
			}
			
			return new $tokenName($parameters);
		}
		
		/**
		 * Get a value from a nested array using dot notation
		 * @param string $path The dot notation path (e.g. "config.cache.default_ttl")
		 * @param string $default Value to return if the path doesn't exist
		 * @return mixed The value found at the path or the default
		 */
		private function resolveNestedValue(string $path, string $default = ''): mixed {
			// Split the path into individual keys
			$keys = explode('.', $path);
			$current = $this->configuration;
			
			// Traverse the array using each segment of the path
			foreach ($keys as $key) {
				if (!is_array($current) || !array_key_exists($key, $current)) {
					return $default;
				}
				
				$current = $current[$key];
			}
			
			return $current;
		}
		
		/**
		 * Resolves a partially qualified class name against imported namespaces.
		 * Caches results for faster repeated lookups.
		 * @param string $className The class name to resolve (e.g., "Validation\Type")
		 * @return string Fully qualified class name if resolved, otherwise original class name.
		 */
		private function resolveClassReference(string $className): string {
			// Return cached data if available
			if (isset($this->classReferenceCache[$className])) {
				return $this->classReferenceCache[$className];
			}
			
			// If the class name does not contain a namespace separator, no resolution needed
			if (!str_contains($className, '\\')) {
				return $this->classReferenceCache[$className] = $className;
			}
			
			// Fetch parts information
			$parts = explode('\\', $className);
			$firstPart = $parts[0];
			$lastPart = end($parts);

			// 1. Look for direct alias match with the first part
			if (isset($this->preprocessed_imports[$firstPart])) {
				$import = $this->preprocessed_imports[$firstPart];
				$candidateClass = $import['namespace'] . '\\' . $className;
				
				if (class_exists($candidateClass) || interface_exists($candidateClass)) {
					return $this->classReferenceCache[$className] = $candidateClass;
				}
			}
			
			// 2. Look for imports that contain the first part in their segments
			foreach ($this->preprocessed_imports as $import) {
				$position = array_search($firstPart, $import['segments'], true);
				
				if ($position !== false) {
					$baseNamespace = implode('\\', array_slice($import['segments'], 0, $position));
					$candidateClass = $baseNamespace . '\\' . $className;
					
					if (class_exists($candidateClass) || interface_exists($candidateClass)) {
						return $this->classReferenceCache[$className] = $candidateClass;
					}
				}
			}
			
			// 3. Try to match the last part with imported classes that contain the first part
			foreach ($this->preprocessed_imports as $import) {
				if ($import['lastPart'] === $lastPart &&
					in_array($firstPart, $import['segments'], true)) {
					return $this->classReferenceCache[$className] = $import['fqcn'];
				}
			}
			
			// 4. Try namespace-based resolution using the preprocessed namespace map
			if (isset($this->namespace_map[$firstPart])) {
				foreach ($this->namespace_map[$firstPart] as $namespace) {
					$candidateClass = $namespace . '\\' . implode('\\', array_slice($parts, 1));
					
					if (class_exists($candidateClass) || interface_exists($candidateClass)) {
						return $this->classReferenceCache[$className] = $candidateClass;
					}
				}
			}
			
			return $this->classReferenceCache[$className] = $className;
		}
		
		/**
		 * Resolves a class name to its fully qualified form using available imports and namespace context.
		 *
		 * This method handles multiple resolution strategies:
		 * 1. Fully qualified names (starting with \)
		 * 2. Imported aliases and their compound variations
		 * 3. Current namespace resolution with fallback to global scope
		 *
		 * @param string $className The class name to resolve (can be simple name, alias, or compound)
		 * @return string The fully qualified class name without leading backslash
		 */
		private function resolveClassName(string $className): string {
			// Handle fully qualified names - remove leading backslash and return
			if (str_starts_with($className, '\\')) {
				return substr($className, 1);
			}
			
			// Strategy 1: Direct alias lookup
			// Check if the entire class name matches an imported alias
			if (isset($this->imports[$className])) {
				return $this->imports[$className];
			}
			
			// Strategy 2: Compound name resolution using imports
			// Handle cases like 'AliasedNamespace\ClassName' where 'AliasedNamespace' is imported
			if (str_contains($className, '\\')) {
				$parts = explode('\\', $className, 2);
				$rootAlias = $parts[0];
				$remainingPath = $parts[1];
				
				// Check if the root part matches an imported alias
				if (isset($this->imports[$rootAlias])) {
					return $this->imports[$rootAlias] . '\\' . $remainingPath;
				}
				
				// Attempt resolution using generic partial namespace resolver
				$resolved = $this->resolveClassReference($className);
				
				// If generic resolver found a different result, use it
				if ($resolved !== $className) {
					return ltrim($resolved, '\\');
				}
			}
			
			// Strategy 3: Current namespace resolution with global fallback
			// Try to resolve within the current namespace, then fall back to global scope
			if ($this->currentNamespace !== null) {
				$candidates = [
					$this->currentNamespace . '\\' . $className,   // Current namespace
					$className                                     // Global namespace
				];
				
				// Test each candidate to see if it exists
				foreach ($candidates as $candidate) {
					if (class_exists($candidate) || interface_exists($candidate)) {
						return $candidate;
					}
				}
			}
			
			// No resolution found - return the original class name unchanged
			return $className;
		}
	}