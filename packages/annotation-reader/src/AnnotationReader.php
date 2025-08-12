<?php
	
	namespace Quellabs\AnnotationReader;
	
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\AnnotationReader\Exception\LexerException;
	use Quellabs\AnnotationReader\Exception\ParserException;
	use Quellabs\AnnotationReader\LexerParser\Lexer;
	use Quellabs\AnnotationReader\LexerParser\Parser;
	use Quellabs\AnnotationReader\LexerParser\UseStatementParser;
	
	class AnnotationReader {
		
		protected UseStatementParser $use_statement_parser;
		protected string $annotationCachePath;
		protected bool $useCache;
		protected array $configuration;
		protected array $cached_annotations;
		
		/**
		 * AnnotationReader constructor
		 */
		public function __construct(Configuration $configuration) {
			// Store annotation cache information
			$this->useCache = $configuration->useAnnotationCache();
			$this->annotationCachePath = $configuration->getAnnotationCachePath();
			
			// Instantiate use statement parser
			$this->use_statement_parser = new UseStatementParser();
			
			// Store the configuration array
			$this->configuration = [];
			
			// read cached data
			$this->cached_annotations = [];
		}
		
		/**
		 * Get class annotations including inherited ones
		 * @param mixed $class The class object or class name to analyze
		 * @param string|null $annotationClass Optional filter to return only annotations of a specific class
		 * @return AnnotationCollection
		 * @throws AnnotationReaderException
		 */
		public function getClassAnnotations(mixed $class, ?string $annotationClass = null): AnnotationCollection {
			// Process from parent to child (so child annotations can override)
			$annotations = $this->getAllObjectAnnotations($class);
			
			// Return an empty collection if no annotations found
			if (empty($annotations['class'])) {
				return new AnnotationCollection();
			}
			
			// Apply annotation class filter if provided
			if ($annotationClass !== null) {
				return $annotations['class']->filter(function ($item) use ($annotationClass) {
					return $item instanceof $annotationClass;
				});
			}
			
			return $annotations['class'];
		}
		
		/**
		 * Checks if a given entity class has a specific annotation.
		 * @param mixed $class            The object to check
		 * @param string $annotationClass The annotation class to look for
		 * @return bool                   True if the annotation exists on the property, false otherwise
		 */
		public function classHasAnnotation(mixed $class, string $annotationClass): bool {
			try {
				$annotations = $this->getClassAnnotations($class, $annotationClass);
				return !$annotations->isEmpty();
			} catch (AnnotationReaderException $e) {
				return false;
			}
		}
		
		/**
		 * Takes a method's docComment and parses it to extract annotations
		 * @param mixed $class The class object or class name to analyze
		 * @param string $methodName The name of the method whose annotations to retrieve
		 * @param string|null $annotationClass Optional filter to return only annotations of a specific class
		 * @return AnnotationCollection    Array of parsed annotations for the specified method
		 * @throws AnnotationReaderException
		 */
		public function getMethodAnnotations(mixed $class, string $methodName, ?string $annotationClass=null): AnnotationCollection {
			// Get all annotations for the method
			$annotations = $this->getAllObjectAnnotations($class);
			
			// If no annotations found, return an empty array
			if (!isset($annotations['methods'][$methodName])) {
				return new AnnotationCollection();
			}
			
			// If an annotation class filter is provided, only return annotations of that type
			if ($annotationClass !== null) {
				return $annotations['methods'][$methodName]->filter(function ($item) use ($annotationClass) {
					// Filter the method's annotations to include only instances of the specified class
					return $item instanceof $annotationClass;
				});
			}
			
			// Return all annotations for the specified method
			return $annotations["methods"][$methodName];
		}
		
		/**
		 * Checks if a method in a given entity class has a specific annotation.
		 * @param mixed $class            The object to check
		 * @param string $methodName      The name of the method to inspect for annotations
		 * @param string $annotationClass The annotation class to look for
		 * @return bool                   True if the annotation exists on the method, false otherwise
		 */
		public function methodHasAnnotation(mixed $class, string $methodName, string $annotationClass): bool {
			try {
				$annotations = $this->getMethodAnnotations($class, $methodName, $annotationClass);
				return !$annotations->isEmpty();
			} catch (AnnotationReaderException $e) {
				return false;
			}
		}
		
		/**
		 * Takes a property's docComment and parses it
		 * @param mixed $class
		 * @param string $propertyName
		 * @return AnnotationCollection
		 * @throws AnnotationReaderException
		 */
		public function getPropertyAnnotations(mixed $class, string $propertyName, ?string $annotationClass=null): AnnotationCollection {
			// Get all annotations for the property
			$annotations = $this->getAllObjectAnnotations($class);
			
			// If no annotations found, return an empty array
			if (!isset($annotations['properties'][$propertyName])) {
				return new AnnotationCollection();
			}
			
			// If an annotation class filter is provided, only return annotations of that type
			if ($annotationClass !== null) {
				return $annotations['properties'][$propertyName]->filter(function ($item) use ($annotationClass) {
					// Filter the method's annotations to include only instances of the specified class
					return $item instanceof $annotationClass;
				});
			}
			
			// Return all annotations for the specified method
			return $annotations["properties"][$propertyName];
		}
		
		/**
		 * Checks if a method in a given entity class has a specific annotation.
		 * @param mixed $class            The object to check
		 * @param string $propertyName    The name of the property to inspect for annotations
		 * @param string $annotationClass The annotation class to look for
		 * @return bool                   True if the annotation exists on the property, false otherwise
		 */
		public function propertyHasAnnotation(mixed $class, string $propertyName, string $annotationClass): bool {
			try {
				$annotations = $this->getPropertyAnnotations($class, $propertyName, $annotationClass);
				return !$annotations->isEmpty();
			} catch (AnnotationReaderException $e) {
				return false;
			}
		}
		
		/**
		 * Parses a string and returns the found annotations
		 * @param string $string
		 * @return AnnotationCollection
		 * @throws AnnotationReaderException
		 */
		public function getAnnotations(string $string): AnnotationCollection {
			try {
				$lexer = new Lexer($string);
				$parser = new Parser($lexer, $this->configuration);
				return $parser->parse();
			} catch (LexerException | ParserException $e) {
				throw new AnnotationReaderException($e->getMessage(), $e->getCode(), $e);
			}
		}

		/**
		 * Transforms a className to a filename
		 * @param string $className
		 * @return string
		 */
		protected function generateCacheFilename(string $className): string {
			return str_replace("\\", "#", $className) . ".cache";
		}
		
		/**
		 * Reads from cache
		 * @param string $cacheFilename
		 * @return array|null
		 */
		protected function readCacheFromFile(string $cacheFilename): ?array {
			// Build the full path to the cache file by combining the base cache directory
			// with the provided filename
			$cachePath = "{$this->annotationCachePath}/{$cacheFilename}";
			
			// Attempt to read the entire file contents into a string
			// This will return false if the file doesn't exist or can't be read
			$fileContents = file_get_contents($cachePath);
			
			// Check if file reading failed (file doesn't exist, permissions issue, etc.)
			if ($fileContents === false) {
				return null;
			}
			
			// Deserialize the cached data from string format back to PHP array/object
			// The cache is expected to contain serialized PHP data
			$unserializedData = unserialize($fileContents);
			
			// Check if unserialization failed (corrupted data, invalid format, etc.)
			// unserialize() returns false on failure
			if ($unserializedData === false) {
				return null;
			}
			
			// Return the successfully deserialized cache data
			return $unserializedData;
		}
		
		/**
		 * Updates the cache
		 * @param string $cacheFilename
		 * @param array $annotations
		 * @return void
		 */
		protected function writeCacheToFile(string $cacheFilename, array $annotations): void {
			// Ensure the cache directory exists before attempting to create files
			// This is important for first-time setup or when deploying to new environments
			if (!is_dir($this->annotationCachePath)) {
				// Create the directory structure recursively with standard permissions
				// 0755 allows the owner to read/write/execute and others to read/execute
				// The 'true' parameter creates parent directories as needed
				mkdir($this->annotationCachePath, 0755, true);
			}
			
			// Create the cache path
			$cachePath = $this->annotationCachePath . DIRECTORY_SEPARATOR . $cacheFilename;

			// Write the file to the path
			file_put_contents($cachePath, serialize($annotations));
		}
		
		/**
		 * Validates whether the annotation cache for a class is still valid.
		 * @param string $cacheFilename The name of the cache file to check
		 * @param \ReflectionClass $reflection Reflection object for the class being cached
		 * @return bool Returns true if the cache is valid, false if it needs to be regenerated
		 */
		protected function cacheValid(string $cacheFilename, \ReflectionClass $reflection): bool {
			// Check if the cache file exists at all
			// If it doesn't exist, we need to generate the cache so return false
			if (!file_exists("{$this->annotationCachePath}/{$cacheFilename}")) {
				return false;
			}
			
			// Check if the cache file is readable
			if (!is_readable("{$this->annotationCachePath}/{$cacheFilename}")) {
				return false;
			}
			
			// Get the last modification time of the class file
			// This helps us determine if the class has been changed since cache creation
			$classCreateDate = filemtime($reflection->getFileName());
			
			// Get the last modification time of the cache file
			// This tells us when the cache was last generated
			$cacheCreateDate = filemtime("{$this->annotationCachePath}/{$cacheFilename}");
			
			// Compare timestamps to determine cache validity
			// Return true if the cache was created after the class was last modified
			// Return false if the class has been modified since the cache was created
			return $classCreateDate <= $cacheCreateDate;
		}
		
		/**
		 * Parses class-level annotations from a docblock comment
		 * @param \ReflectionClass $reflection Reflection class
		 * @param array|AnnotationCollection &$result Reference to the result array where parsed annotations will be stored
		 * @param array $imports Array of imported namespaces/classes for annotation resolution
		 * @return void
		 * @throws AnnotationReaderException When annotation parsing fails
		 */
		protected function parseClassAnnotations(\ReflectionClass $reflection, array|AnnotationCollection &$result, array $imports) : void {
			// Early return if no docblock comment exists or if it's empty
			if (empty($reflection->getDocComment())) {
				return;
			}
			
			try {
				// Create a lexer and parser and parse the class
				$lexer = new Lexer($reflection->getDocComment());
				$parser = new Parser($lexer, $this->configuration, $imports, $reflection->getNamespaceName());
				
				// Parse the class and store the result
				$result = $parser->parse();
			} catch (LexerException|ParserException $e) {
				// Wrap parsing exceptions in a more specific exception type
				// Note: $reflection variable appears to be missing from the original code
				throw new AnnotationReaderException(
					"Failed to parse class annotations for '{$reflection->getName()}': {$e->getMessage()}",
					$e->getCode(),
					$e
				);
			}
		}
		
		/**
		 * Parse annotations for properties or methods and update the result array.
		 * @param array $items An array of ReflectionProperty or ReflectionMethod objects.
		 * @param array $result The result array to be updated with parsed annotations.
		 * @param array $imports The import list
		 * @param string|null $currentNamespace The namespace of the file we're currently reading
		 * @return void
		 * @throws AnnotationReaderException
		 */
		protected function parseAnnotations(array $items, array &$result, array $imports, ?string $currentNamespace=null): void {
			// Loop through each Reflection item (either property or method)
			foreach ($items as $item) {
				try {
					// Get the doc comment for the current item
					$docComment = $item->getDocComment();
					
					// Skip if there is no doc comment
					if (empty($docComment)) {
						continue;
					}
					
					// Retrieve annotations from the doc comment with imports
					$lexer = new Lexer($docComment);
					$parser = new Parser($lexer, $this->configuration, $imports, $currentNamespace);
					$annotations = $parser->parse();
					
					// Skip if there are no annotations
					if ($annotations->isEmpty()) {
						continue;
					}
					
					// Add the annotations to the result array
					$result[$item->getName()] = $annotations;
				} catch (ParserException | LexerException $e) {
					// Determine if this is a property or method
					$itemType = $item instanceof \ReflectionProperty ? 'property' : 'method';
					$itemName = $item->getName();
					$className = $item->getDeclaringClass()->getName();
					
					throw new AnnotationReaderException(
						"Failed to parse annotations for {$itemType} '{$itemName}' in class '{$className}': {$e->getMessage()}",
						$e->getCode(),
						$e
					);
				}
			}
		}
		
		/**
		 * Fetch all object annotations
		 * @param \ReflectionClass $reflection
		 * @return array
		 * @throws AnnotationReaderException
		 */
		protected function readAllObjectAnnotations(\ReflectionClass $reflection): array {
			// Setup array which will receive the parse results
			$result = [
				'class'      => [],
				'properties' => [],
				'methods'    => []
			];
			
			// Load the use statements of this file
			$imports = $this->use_statement_parser->getImportsForClass($reflection);
			
			// Parse the annotations and return result
			$this->parseClassAnnotations($reflection, $result['class'], $imports);
			$this->parseAnnotations($reflection->getProperties(), $result['properties'], $imports, $reflection->getNamespaceName());
			$this->parseAnnotations($reflection->getMethods(), $result['methods'], $imports, $reflection->getNamespaceName());
			return $result;
		}

		/**
		 * Retrieve all annotations for a given class, caching the results for performance.
		 * @param mixed $class The fully qualified class name to get annotations for.
		 * @return array An array containing all annotations for the class, its properties, and its methods.
		 * @throws AnnotationReaderException
		 */
		protected function getAllObjectAnnotations(mixed $class): array {
			try {
				// Create a ReflectionClass object for the given class
				// This provides metadata about the class structure and properties
				$reflection = new \ReflectionClass($class);
				$className = $reflection->getName();
				
				// Generate a cache filename based on the class name
				// This provides a unique identifier for storing and retrieving cached annotations
				$cacheFilename = $this->generateCacheFilename($className);
				
				// Check if annotations for this class are already cached in memory
				// If so, return them immediately to avoid redundant processing
				if (isset($this->cached_annotations[$cacheFilename])) {
					return $this->cached_annotations[$cacheFilename];
				}
				
				// If caching is disabled or no cache path is set, process annotations directly
				// We still store in memory cache to avoid redundant processing within the same request
				if (!$this->useCache || empty($this->annotationCachePath)) {
					$this->cached_annotations[$cacheFilename] = $this->readAllObjectAnnotations($reflection);
					return $this->cached_annotations[$cacheFilename];
				}
				
				// Check if a valid cache file exists and is up to date
				// This compares file modification times to determine if cache is stale
				if ($this->cacheValid($cacheFilename, $reflection)) {
					$cachedData = $this->readCacheFromFile($cacheFilename);
					
					if ($cachedData !== null) {
						$this->cached_annotations[$cacheFilename] = $cachedData;
						return $this->cached_annotations[$cacheFilename];
					}
				}
				
				// If no valid cache exists, parse the annotations directly from the class
				$annotations = $this->readAllObjectAnnotations($reflection);
				
				// Write the newly parsed annotations to the cache file
				// This will speed up future requests for this class's annotations
				$this->writeCacheToFile($cacheFilename, $annotations);
				
				// Also store the annotations in memory cache for this request lifecycle
				$this->cached_annotations[$cacheFilename] = $annotations;
				
				// Return the annotations to the caller
				return $annotations;
			} catch (\ReflectionException $e) {
				$classIdentifier = is_string($class) ? $class : get_class($class);
				
				throw new AnnotationReaderException(
					"Failed to create reflection for class '{$classIdentifier}': {$e->getMessage()}",
					$e->getCode(),
					$e
				);
			}
		}
	}