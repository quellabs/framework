<?php
	
	namespace Quellabs\AnnotationReader\LexerParser;
	
	/**
	 * Class UseStatementParser
	 * Parses PHP use statements from a class using reflection
	 */
	class UseStatementParser {
		
		/**
		 * @var array<string, array<string, string>> Cache of imports by class
		 */
		private array $importsCache = [];
		
		/**
		 * Get all imported class aliases from use statements in the given class
		 * @param \ReflectionClass $class
		 * @return array<string, string> Map of aliases to fully qualified class names
		 */
		public function getImportsForClass(\ReflectionClass $class): array {
			$className = $class->getName();
			
			// Return cached result if available
			if (isset($this->importsCache[$className])) {
				return $this->importsCache[$className];
			}
			
			// Get namespace and imports
			$imports = $this->parseUseStatements($class);
			
			// Cache and return
			$this->importsCache[$className] = $imports;
			return $imports;
		}
		
		/**
		 * Parse use statements from a class file using a direct regex approach
		 * @param \ReflectionClass $class The reflection class to analyze
		 * @return array<string, string> Map of aliases to fully qualified class names
		 */
		private function parseUseStatements(\ReflectionClass $class): array {
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
			
			// Regular expression to match use statements at the beginning of lines
			// Breakdown of the pattern:
			// ^\s* - Start of line followed by optional whitespace
			// use\s+ - The word "use" followed by required whitespace
			// ([^;]+) - Capture group: everything except semicolon (the use statement content)
			// ; - Literal semicolon that ends the use statement
			// /m - Multiline flag so ^ matches line beginnings, not just string start
			$pattern = '/^\s*use\s+([^;]+);/m';
			
			// Execute the regex and capture all matches
			// $matches[0] contains full matches, $matches[1] contains captured groups
			if (preg_match_all($pattern, $content, $matches)) {
				// Process each captured use statement
				foreach ($matches[1] as $useStatement) {
					// Remove leading/trailing whitespace from the use statement content
					$useStatement = trim($useStatement);
					
					// Check if this is a grouped use statement (PSR-4 style grouping)
					// Example: use Some\Namespace\{ClassA, ClassB, ClassC};
					// Look for both opening and closing curly braces
					if (str_contains($useStatement, '{') && str_contains($useStatement, '}')) {
						$this->parseGroupedUseStatement($useStatement, $imports);
					} else {
						$this->parseSingleUseStatement($useStatement, $imports);
					}
				}
			}
			
			// Return the complete mapping of aliases to fully qualified names
			return $imports;
		}
		
		/**
		 * Parse a single use statement (non-grouped)
		 * @param string $useStatement The use statement to parse
		 * @param array &$imports Reference to the imports array to populate
		 * @return void
		 */
		private function parseSingleUseStatement(string $useStatement, array &$imports): void {
			// Handle aliased use statements: "ClassName as Alias"
			if (str_contains($useStatement, ' as ')) {
				list($className, $alias) = explode(' as ', $useStatement, 2);
				$className = trim($className);
				$alias = trim($alias);
				$imports[$alias] = $className;
			} else {
				// Regular use statement: extract short name from full namespace
				$className = trim($useStatement);
				$shortName = $this->getShortClassName($className);
				$imports[$shortName] = $className;
			}
		}
		
		/**
		 * Parse a grouped use statement (with curly braces)
		 * @param string $useStatement The grouped use statement to parse
		 * @param array &$imports Reference to the imports array to populate
		 * @return void
		 */
		private function parseGroupedUseStatement(string $useStatement, array &$imports): void {
			// Extract base namespace and class list from: "Namespace\{Class1, Class2}"
			if (preg_match('/^(.+?)\s*\{\s*([^}]+)\s*}$/', $useStatement, $matches)) {
				$baseNamespace = trim($matches[1]);
				$classListString = trim($matches[2]);
				
				// Split individual classes by comma
				$classes = explode(',', $classListString);
				
				foreach ($classes as $classItem) {
					$classItem = trim($classItem);
					
					// Skip empty entries (trailing commas, extra spaces)
					if (empty($classItem)) {
						continue;
					}
					
					// Handle aliased classes: "ClassName as Alias"
					if (str_contains($classItem, ' as ')) {
						list($className, $alias) = explode(' as ', $classItem, 2);
						$className = trim($className);
						$alias = trim($alias);
						$fullClassName = $baseNamespace . '\\' . $className;
						$imports[$alias] = $fullClassName;
					} else {
						// Regular class: combine base namespace with class name
						$className = trim($classItem);
						$fullClassName = $baseNamespace . '\\' . $className;
						$shortName = $this->getShortClassName($className);
						$imports[$shortName] = $fullClassName;
					}
				}
			}
		}
		
		/**
		 * Get the short class name from a fully qualified class name
		 * @param string $className Fully qualified class name
		 * @return string Short class name
		 */
		private function getShortClassName(string $className): string {
			// Remove any leading backslash
			$className = ltrim($className, '\\');
			
			// Handle an empty case
			if (empty($className)) {
				return '';
			}
			
			$parts = explode('\\', $className);
			return end($parts);
		}
	}