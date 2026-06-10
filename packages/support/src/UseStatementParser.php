<?php
	
	namespace Quellabs\Support;
	
	use ReflectionClass;
	
	/**
	 * Class UseStatementParser
	 * Parses PHP use statements from a class using PHP's tokenizer
	 */
	class UseStatementParser {
		
		/**
		 * @var array<string, array<string, string>> Cache of imports by class
		 * Note: this cache is never invalidated within a process lifetime. For normal
		 * PHP-FPM/CLI usage this is fine. Under long-lived workers (Swoole, RoadRunner,
		 * ReactPHP) a source file change will not be reflected until the worker restarts.
		 */
		private static array $importsCache = [];
		
		/**
		 * Get all imported class aliases from use statements in the given class.
		 * Returns only class imports; function/const imports are excluded.
		 * @param ReflectionClass<object> $class
		 * @return array<string, string> Map of aliases to fully qualified class names
		 */
		public static function getImportsForClass(ReflectionClass $class): array {
			// Get class name using reflection
			$className = $class->getName();
			
			// Return cached result if available
			if (isset(self::$importsCache[$className])) {
				return self::$importsCache[$className];
			}
			
			// Get namespace and imports
			$imports = self::parseUseStatements($class);
			
			// Cache and return
			self::$importsCache[$className] = $imports;
			return $imports;
		}
		
		/**
		 * Parse use statements from a class file using token_get_all().
		 * Stops scanning once the class/interface/trait/enum declaration is reached,
		 * so trait use statements inside the body are never included.
		 * @param ReflectionClass<object> $class The reflection class to analyze
		 * @return array<string, string> Map of aliases to fully qualified class names
		 */
		private static function parseUseStatements(ReflectionClass $class): array {
			// Skip for classes defined in PHP core (e.g., stdClass, Exception)
			// Internal classes don't have source files and therefore no use statements
			if ($class->isInternal()) {
				return [];
			}
			
			// Get the filename where the class is defined
			$filename = $class->getFileName();
			
			// Skip if the file doesn't exist or getFileName() returns false
			// This can happen with dynamically created classes or eval'd code
			if ($filename === false || !file_exists($filename)) {
				return [];
			}
			
			// Read the entire file content into memory
			$content = file_get_contents($filename);
			
			// Handle case where file reading fails (permissions, I/O errors, etc.)
			if ($content === false) {
				return [];
			}
			
			// Initialize the array to store parsed imports
			// Key = alias/short name, Value = fully qualified class name
			$imports = [];
			
			$tokens = token_get_all($content);
			$i = 0;
			$count = count($tokens);
			
			while ($i < $count) {
				$token = $tokens[$i];
				
				// Stop at class/interface/trait/enum body — everything after this
				// is either the class declaration or its body, not file-level imports.
				// Trait use statements inside a class body are intentionally excluded.
				if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
					break;
				}
				
				// Only process T_USE tokens
				if (!is_array($token) || $token[0] !== T_USE) {
					$i++;
					continue;
				}
				
				$i++;
				
				// Skip whitespace after 'use'
				$i = self::skipWhitespace($tokens, $i, $count);
				
				// Detect use function / use const — these are not class imports.
				// T_FUNCTION and T_CONST are the relevant token types here.
				$importKind = 'class';
				
				if (isset($tokens[$i]) && is_array($tokens[$i])) {
					if ($tokens[$i][0] === T_FUNCTION) {
						$importKind = 'function';
						$i++;
						$i = self::skipWhitespace($tokens, $i, $count);
					} elseif ($tokens[$i][0] === T_CONST) {
						$importKind = 'const';
						$i++;
						$i = self::skipWhitespace($tokens, $i, $count);
					}
				}
				
				// Collect all tokens up to the terminating semicolon
				$statementTokens = [];
				
				while ($i < $count) {
					$t = $tokens[$i];
					
					if ($t === ';') {
						$i++;
						break;
					}
					
					$statementTokens[] = $t;
					$i++;
				}
				
				// Reconstruct the raw use statement string from tokens.
				// Whitespace tokens are normalized to a single space to make
				// subsequent parsing reliable regardless of source formatting.
				$statement = '';
				
				foreach ($statementTokens as $t) {
					if (is_array($t)) {
						$statement .= ($t[0] === T_WHITESPACE) ? ' ' : $t[1];
					} else {
						$statement .= $t;
					}
				}
				
				$statement = trim($statement);
				
				if ($statement === '') {
					continue;
				}
				
				// Check if this is a grouped use statement (PSR-4 style grouping)
				// Example: use Some\Namespace\{ClassA, ClassB, ClassC};
				// Look for both opening and closing curly braces
				if (str_contains($statement, '{')) {
					self::parseGroupedUseStatement($statement, $importKind, $imports);
				} else {
					// PHP allows multiple imports in a single statement separated by commas:
					//   use Foo\Bar, Foo\Baz;
					//   use Foo\Bar as B, Foo\Baz as Z;
					// Split on comma and process each entry independently.
					$entries = explode(',', $statement);
					
					foreach ($entries as $entry) {
						self::parseSingleUseStatement(trim($entry), $importKind, $imports);
					}
				}
			}
			
			// Return the complete mapping of aliases to fully qualified names
			return $imports;
		}
		
		/**
		 * Parse a single (non-grouped) use statement.
		 * Only class imports are added to $imports; function/const imports are ignored.
		 * @param string $statement         The use statement body (without leading 'use' or trailing ';')
		 * @param string $importKind        'class', 'function', or 'const'
		 * @param array<string, string> &$imports Reference to the imports array to populate
		 * @return void
		 */
		private static function parseSingleUseStatement(string $statement, string $importKind, array &$imports): void {
			if ($importKind !== 'class') {
				return;
			}
			
			// Handle aliased use statements: "ClassName as Alias"
			// PHP's 'as' keyword is case-insensitive; use a regex match rather than
			// a plain str_contains check to handle AS / As / aS variants correctly.
			if (preg_match('/^(.+?)\s+as\s+(\S+)$/i', $statement, $matches)) {
				$className = trim($matches[1]);
				$alias = trim($matches[2]);
				$imports[$alias] = $className;
			} else {
				// Regular use statement: extract short name from full namespace
				$className = trim($statement);
				$shortName = self::getShortClassName($className);
				$imports[$shortName] = $className;
			}
		}
		
		/**
		 * Parse a grouped use statement (with curly braces).
		 * Handles aliased entries and respects importKind so that
		 * `use function Ns\{helperA, helperB}` entries are excluded.
		 * @param string $statement         The use statement body (without leading 'use' or trailing ';')
		 * @param string $importKind        'class', 'function', or 'const'
		 * @param array<string, string> &$imports Reference to the imports array to populate
		 * @return void
		 */
		private static function parseGroupedUseStatement(string $statement, string $importKind, array &$imports): void {
			// Extract base namespace and class list from: "Namespace\{Class1, Class2}"
			if (!preg_match('/^(.+?)\s*\{\s*([^}]+)\s*}$/', $statement, $matches)) {
				return;
			}
			
			// Normalize base namespace - remove trailing backslashes
			$baseNamespace = rtrim(trim($matches[1]), '\\');
			$classListString = trim($matches[2]);
			
			// Split individual classes by comma
			$entries = explode(',', $classListString);
			
			foreach ($entries as $entry) {
				$entry = trim($entry);
				
				// Skip empty entries (trailing commas, extra spaces)
				if ($entry === '') {
					continue;
				}
				
				// Each entry may be prefixed with 'function' or 'const':
				//   use Ns\{ function helperA, ClassName }
				// Detect and strip that prefix to determine per-entry kind.
				$entryKind = $importKind;
				
				if (preg_match('/^function\s+(.+)$/i', $entry, $km)) {
					$entryKind = 'function';
					$entry = trim($km[1]);
				} elseif (preg_match('/^const\s+(.+)$/i', $entry, $km)) {
					$entryKind = 'const';
					$entry = trim($km[1]);
				}
				
				if ($entryKind !== 'class') {
					continue;
				}
				
				// Handle aliased classes: "ClassName as Alias"
				if (preg_match('/^(.+?)\s+as\s+(\S+)$/i', $entry, $am)) {
					$className = trim($am[1]);
					$alias = trim($am[2]);
					$imports[$alias] = $baseNamespace . '\\' . $className;
				} else {
					// Regular class: combine base namespace with class name
					$className = trim($entry);
					$fullClassName = $baseNamespace . '\\' . $className;
					$shortName = self::getShortClassName($className);
					$imports[$shortName] = $fullClassName;
				}
			}
		}
		
		/**
		 * Get the short class name from a fully qualified class name
		 * @param string $className Fully qualified class name
		 * @return string Short class name
		 */
		private static function getShortClassName(string $className): string {
			// Remove any leading backslash
			$className = ltrim($className, '\\');
			
			// Handle an empty case
			if (empty($className)) {
				return '';
			}
			
			$parts = explode('\\', $className);
			return end($parts);
		}
		
		/**
		 * Advance the token index past any whitespace tokens.
		 * @param array<int, mixed> $tokens
		 * @param int $i     Current index
		 * @param int $count Total token count
		 * @return int Updated index pointing at the first non-whitespace token
		 */
		private static function skipWhitespace(array $tokens, int $i, int $count): int {
			while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
				$i++;
			}
			
			return $i;
		}
	}