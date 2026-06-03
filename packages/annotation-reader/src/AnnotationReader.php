<?php
	
	namespace Quellabs\AnnotationReader;
	
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\AnnotationReader\Exception\LexerException;
	use Quellabs\AnnotationReader\Exception\ParserException;
	use Quellabs\AnnotationReader\LexerParser\Lexer;
	use Quellabs\AnnotationReader\LexerParser\Parser;
	
	/**
	 * @phpstan-type AnnotationSet array{
	 *    class: AnnotationCollection,
	 *    properties: array<string, AnnotationCollection>,
	 *    methods: array<string, AnnotationCollection>
	 *  }
	 *
	 * @phpstan-type SerializedAnnotation array{
	 *     class: class-string,
	 *     parameters: array<string, mixed>
	 * }
	 */
	class AnnotationReader {
		
		/** @var bool Write cache files yes/no */
		protected bool $useCache;
		
		/** @var string Directory to store cache files in */
		protected string $annotationCachePath;
		
		/** @var bool When true, cache files are re-validated against source mtime on every read */
		protected bool $debugMode;
		
		/** @var array<string, mixed> */
		protected array $configuration;
		
		/** @var array<string, AnnotationSet> */
		protected array $cached_annotations;
		
		/**
		 * AnnotationReader constructor
		 * @param Configuration $configuration
		 */
		public function __construct(Configuration $configuration) {
			$this->useCache = $configuration->useAnnotationCache();
			$this->annotationCachePath = $configuration->getAnnotationCachePath();
			$this->debugMode = $configuration->isDebugMode();
			$this->configuration = [];
			$this->cached_annotations = [];
		}
		
		/**
		 * Get class annotations including inherited ones
		 * @template T of AnnotationInterface
		 * @param class-string|object $class The class object or class name to analyze
		 * @param class-string<T>|null $annotationClass Optional filter to return only annotations of a specific class
		 * @return ($annotationClass is null ? AnnotationCollection : AnnotationCollection<T>)
		 * @throws AnnotationReaderException
		 */
		public function getClassAnnotations(string|object $class, ?string $annotationClass = null): AnnotationCollection {
			// Process from parent to child (so child annotations can override)
			$annotations = $this->getAllObjectAnnotations($class);
			
			// Return an empty collection if no annotations found
			if ($annotations['class']->isEmpty()) {
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
		 * @template T of AnnotationInterface
		 * @param class-string|object $class The object to check
		 * @param class-string<T> $annotationClass The annotation class to look for
		 * @return bool True if the annotation exists on the property, false otherwise
		 */
		public function classHasAnnotation(string|object $class, string $annotationClass): bool {
			try {
				$annotations = $this->getClassAnnotations($class, $annotationClass);
				return !$annotations->isEmpty();
			} catch (AnnotationReaderException $e) {
				return false;
			}
		}
		
		/**
		 * Takes a method's docComment and parses it to extract annotations
		 * @template T of AnnotationInterface
		 * @param class-string|object $class The class object or class name to analyze
		 * @param string $methodName The name of the method whose annotations to retrieve
		 * @param class-string<T>|null $annotationClass Optional filter to return only annotations of a specific class
		 * @return ($annotationClass is null ? AnnotationCollection : AnnotationCollection<T>)
		 * @throws AnnotationReaderException
		 */
		public function getMethodAnnotations(string|object $class, string $methodName, ?string $annotationClass = null): AnnotationCollection {
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
		 * @template T of AnnotationInterface
		 * @param class-string|object $class The object to check
		 * @param string $methodName The name of the method to inspect for annotations
		 * @param class-string<T> $annotationClass $annotationClass The annotation class to look for
		 * @return bool                   True if the annotation exists on the method, false otherwise
		 */
		public function methodHasAnnotation(string|object $class, string $methodName, string $annotationClass): bool {
			try {
				$annotations = $this->getMethodAnnotations($class, $methodName, $annotationClass);
				return !$annotations->isEmpty();
			} catch (AnnotationReaderException $e) {
				return false;
			}
		}
		
		/**
		 * Takes a property's docComment and parses it
		 * @template T of AnnotationInterface
		 * @param class-string|object $class
		 * @param string $propertyName
		 * @param class-string<T>|null $annotationClass
		 * @return ($annotationClass is null ? AnnotationCollection : AnnotationCollection<T>)
		 * @throws AnnotationReaderException
		 */
		public function getPropertyAnnotations(string|object $class, string $propertyName, ?string $annotationClass = null): AnnotationCollection {
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
		 * @template T of AnnotationInterface
		 * @param class-string|object $class The object to check
		 * @param string $propertyName The name of the property to inspect for annotations
		 * @param class-string<T> $annotationClass The annotation class to look for
		 * @return bool                      True if the annotation exists on the property, false otherwise
		 */
		public function propertyHasAnnotation(string|object $class, string $propertyName, string $annotationClass): bool {
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
			} catch (LexerException|ParserException $e) {
				throw new AnnotationReaderException($e->getMessage(), $e->getCode(), $e);
			}
		}
		
		/**
		 * Deletes all cache files listed in the manifest for the given annotation
		 * class, then removes the manifest itself. Used by CLI cache-clear commands
		 * to invalidate only the cache entries relevant to a specific subsystem
		 * (e.g. routes:clear_cache passes Route::class).
		 *
		 * If the manifest does not exist this is a no-op, so it is safe to call
		 * even when the cache has never been written.
		 *
		 * After deleting the cache files, every other manifest in the cache directory
		 * is updated: any reference to a deleted cache filename is removed. Manifests
		 * that become empty as a result are deleted as well, so the manifest directory
		 * never accumulates stale entries.
		 *
		 * Each manifest is processed under an exclusive lock held across the full
		 * read-modify-write cycle, so concurrent updateManifests() appends cannot
		 * be lost by a simultaneous rewrite.
		 * @param string $annotationClass Fully qualified annotation class name, e.g. Route::class
		 * @return void
		 */
		public function clearCacheByAnnotationClass(string $annotationClass): void {
			$manifestFilename = $this->generateManifestFilename($annotationClass);
			$manifestPath = $this->annotationCachePath . DIRECTORY_SEPARATOR . $manifestFilename;
			
			// Nothing to do if no manifest exists for this annotation type
			if (!file_exists($manifestPath)) {
				return;
			}
			
			// Acquire exclusive lock on the triggering manifest before reading it.
			// This blocks any concurrent updateManifests() append for this manifest,
			// so the snapshot we read is complete and no entries can be added between
			// our read and our unlink.
			$lockHandle = $this->acquireManifestLock($manifestPath);
			
			if ($lockHandle === false) {
				return;
			}
			
			// Read the list of cache filenames from the manifest under the exclusive lock
			$lines = $this->readManifestLocked($manifestPath);
			
			// Release the lock before proceeding
			flock($lockHandle, LOCK_UN);
			fclose($lockHandle);
			
			// If reading failed, bail out without deleting the manifest — its contents
			// were never processed, so removing it would silently discard valid entries
			if ($lines === null) {
				return;
			}
			
			// Remove the triggering manifest now that its contents are safely in $lines.
			// The .lock file is intentionally left in place: deleting it creates a race
			// where a process waiting on flock() and a new process creating the file can
			// end up locking different inodes, invalidating the synchronization guarantee.
			@unlink($manifestPath);
			
			// Build a hash-keyed set for O(1) membership checks when scanning other manifests
			$toDelete = array_flip($lines);
			
			// Delete each cache file listed in the manifest
			foreach ($lines as $cacheFilename) {
				@unlink($this->annotationCachePath . DIRECTORY_SEPARATOR . $cacheFilename);
			}
			
			// Find all remaining manifest files in one syscall
			$otherManifests = glob($this->annotationCachePath . DIRECTORY_SEPARATOR . '*.manifest');
			
			if ($otherManifests === false) {
				return;
			}
			
			// Strip references to deleted cache files from every other manifest.
			// Each manifest is processed under its own exclusive lock to prevent
			// a concurrent updateManifests() append from being silently overwritten.
			foreach ($otherManifests as $otherManifestPath) {
				// Guard against the deleted manifest appearing in glob() results due to
				// filesystem buffering; also makes the exclusion intent explicit.
				if (basename($otherManifestPath) === $manifestFilename) {
					continue;
				}
				
				// Hold the lock for the entire read-filter-write cycle so a concurrent
				// updateManifests() append cannot land between our read and our rewrite
				$otherLock = $this->acquireManifestLock($otherManifestPath);
				
				if ($otherLock === false) {
					continue;
				}
				
				$entries = $this->readManifestLocked($otherManifestPath);
				
				if ($entries === null) {
					flock($otherLock, LOCK_UN);
					fclose($otherLock);
					continue;
				}
				
				// Filter out any cache filenames that were deleted
				$remaining = array_filter($entries, static fn(string $e) => !isset($toDelete[$e]));
				
				if (empty($remaining)) {
					// Manifest is now empty — remove it. The .lock file is left in place
					// intentionally; see the comment above on lock-file lifecycle.
					flock($otherLock, LOCK_UN);
					fclose($otherLock);
					@unlink($otherManifestPath);
					continue;
				}
				
				if (count($remaining) !== count($entries)) {
					// Rewrite atomically while the lock is still held: the lock prevents
					// concurrent appenders from writing to the old inode that rename()
					// will displace, so no entries can be lost between write and rename
					$this->writeManifestAtomic($otherManifestPath, array_values($remaining));
				}
				
				flock($otherLock, LOCK_UN);
				fclose($otherLock);
			}
		}
		
		/**
		 * Deletes all cache and manifest files in the annotation cache directory.
		 * Used by the catch-all cache clear CLI command when a full reset is needed.
		 *
		 * Only files with .cache and .manifest extensions are removed. Lock files
		 * (.lock) are intentionally preserved: they are permanent synchronization
		 * objects whose inode identity must remain stable across processes.
		 * @return void
		 */
		public function clearAllCaches(): void {
			if (!is_dir($this->annotationCachePath)) {
				return;
			}
			
			$files = glob($this->annotationCachePath . DIRECTORY_SEPARATOR . '*.{cache,manifest}', GLOB_BRACE);
			
			if ($files === false) {
				return;
			}
			
			foreach ($files as $file) {
				if (is_file($file)) {
					unlink($file);
				}
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
		 * Serializes an AnnotationCollection to a plain array for JSON storage.
		 * Each annotation is stored as ['class' => FQCN, 'parameters' => [...]] so it
		 * can be reconstructed by calling new $class($parameters), which properly invokes
		 * the constructor and initializes all typed properties.
		 * @param AnnotationCollection $collection
		 * @return list<SerializedAnnotation>
		 */
		protected function serializeCollection(AnnotationCollection $collection): array {
			$result = [];
			
			/** @var AnnotationInterface $annotation */
			foreach ($collection as $annotation) {
				$result[] = [
					'class'      => get_class($annotation),
					'parameters' => $annotation->getParameters(),
				];
			}
			
			return $result;
		}
		
		/**
		 * Reconstructs an AnnotationCollection from a serialized plain array.
		 * Calls new $class($parameters) for each entry so that annotation constructors
		 * run normally and all typed properties are initialized.
		 * @param array<mixed> $data
		 * @return AnnotationCollection
		 */
		protected function deserializeCollection(array $data): AnnotationCollection {
			/** @var array<int, AnnotationInterface> $annotations */
			$annotations = [];
			
			foreach ($data as $entry) {
				// Guard against corrupt cache entries: each entry must be an array
				if (!is_array($entry)) {
					continue;
				}
				
				// Fetch class
				$class = $entry['class'];
				
				// Guard against corrupt cache entries: the class must exist and implement
				// AnnotationInterface so that calling new $class($parameters) is safe and
				// the result is a valid AnnotationCollection element
				if (
					!is_string($class) ||
					!class_exists($class) ||
					!is_a($class, AnnotationInterface::class, true)
				) {
					continue;
				}
				
				// Reconstruct the annotation by calling its constructor with the stored parameters.
				$annotations[] = new $class($entry['parameters']);
			}
			
			return new AnnotationCollection($annotations);
		}
		
		/**
		 * Reads from cache
		 * @param string $cacheFilename
		 * @return AnnotationSet|null
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
			
			// Decode the JSON cache format
			$decoded = json_decode($fileContents, true);
			
			// Check if decoding failed (corrupted data, invalid format, etc.)
			if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
				return null;
			}
			
			// Extract data from json data
			$classData = is_array($decoded['class'] ?? null) ? $decoded['class'] : [];
			$methods = is_array($decoded['methods'] ?? null) ? $decoded['methods'] : [];
			$properties = is_array($decoded['properties'] ?? null) ? $decoded['properties'] : [];
			
			// Reconstruct the AnnotationSet, calling each annotation's constructor
			// so typed properties are properly initialized
			$methodCollections = [];
			$propertyCollections = [];
			
			foreach ($methods as $methodName => $collectionData) {
				/** @var list<SerializedAnnotation> $collectionData */
				$methodCollections[$methodName] = $this->deserializeCollection($collectionData);
			}
			
			foreach ($properties as $propertyName => $collectionData) {
				/** @var list<SerializedAnnotation> $collectionData */
				$propertyCollections[$propertyName] = $this->deserializeCollection($collectionData);
			}
			
			/** @var list<SerializedAnnotation> $classData */
			/** @var AnnotationSet $result */
			$result = [
				'class'      => $this->deserializeCollection($classData),
				'methods'    => $methodCollections,
				'properties' => $propertyCollections,
			];
			
			return $result;
		}
		
		/**
		 * Updates the cache
		 * @param string $cacheFilename
		 * @param AnnotationSet $annotations
		 * @return int|false
		 */
		protected function writeCacheToFile(string $cacheFilename, array $annotations): int|false {
			// Ensure the cache directory exists before attempting to create files.
			// The is_dir() + mkdir() + is_dir() pattern handles the TOCTOU race where
			// two processes both see the directory as missing and both call mkdir():
			// the second mkdir() fails, but the final is_dir() confirms it was created
			// by the first process, so we treat that as success.
			if (!is_dir($this->annotationCachePath) && !mkdir($this->annotationCachePath, 0755, true) && !is_dir($this->annotationCachePath)) {
				return false;
			}
			
			// Encode as JSON rather than using serialize(), so annotation objects are
			// stored as plain data (class name + parameters) and reconstructed via their
			// constructors on read. serialize/unserialize bypasses __construct, leaving
			// typed properties uninitialized.
			$methodData = [];
			$propertyData = [];
			
			foreach ($annotations['methods'] as $methodName => $collection) {
				$methodData[$methodName] = $this->serializeCollection($collection);
			}
			
			foreach ($annotations['properties'] as $propertyName => $collection) {
				$propertyData[$propertyName] = $this->serializeCollection($collection);
			}
			
			// Create the payload
			$payload = json_encode([
				'class'      => $this->serializeCollection($annotations['class']),
				'methods'    => $methodData,
				'properties' => $propertyData,
			]);
			
			$cachePath = $this->annotationCachePath . DIRECTORY_SEPARATOR . $cacheFilename;
			
			// Write to a PID-scoped temp file first, then rename() atomically into place.
			// This prevents a concurrent reader from observing a partially-written JSON payload,
			// which would cause json_decode() to fail and trigger an unnecessary cache regeneration.
			$tmpPath = $cachePath . '.' . getmypid() . '.tmp';
			$written = file_put_contents($tmpPath, $payload, LOCK_EX);
			
			if ($written === false) {
				return false;
			}
			
			// rename() is atomic on POSIX: readers see old or new cache, never partial
			if (!rename($tmpPath, $cachePath)) {
				@unlink($tmpPath);
				return false;
			}
			
			return $written;
		}
		
		/**
		 * Validates whether the annotation cache for a class is still valid.
		 *
		 * In debug mode the cache file's mtime is compared against the source class file
		 * so that annotation changes are picked up immediately during development.
		 *
		 * In production mode the mtime check is skipped entirely: if the cache file
		 * exists and is readable the cache is considered valid. This eliminates two
		 * filemtime() calls per class per request. Cache freshness in production is
		 * the responsibility of the deployment process (run cache:clear on deploy).
		 *
		 * @param string $cacheFilename The name of the cache file to check
		 * @param \ReflectionClass<object> $reflection Reflection object for the class being cached
		 * @return bool Returns true if the cache is valid, false if it needs to be regenerated
		 */
		protected function cacheValid(string $cacheFilename, \ReflectionClass $reflection): bool {
			// Fetch the cache filename
			$cachePath = "{$this->annotationCachePath}/{$cacheFilename}";
			
			// Check if the cache file exists and is readable
			if (!file_exists($cachePath) || !is_readable($cachePath)) {
				return false;
			}
			
			// In production mode, existence of the cache file is sufficient.
			// Skipping filemtime() saves two stat calls per class per request.
			if (!$this->debugMode) {
				return true;
			}
			
			// In debug mode, compare mtime of the cache file against the source class
			// file so annotation changes are reflected without a manual cache clear.
			$fileName = $reflection->getFileName();
			
			if ($fileName === false) {
				return false;
			}
			
			return filemtime($fileName) <= filemtime($cachePath);
		}
		
		/**
		 * Parses class-level annotations from a docblock comment
		 * @param \ReflectionClass<object> $reflection Reflection class
		 * @param AnnotationCollection $result Reference to the result array where parsed annotations will be stored
		 * @return void
		 * @throws AnnotationReaderException When annotation parsing fails
		 */
		protected function parseClassAnnotations(\ReflectionClass $reflection, AnnotationCollection &$result): void {
			// Early return if no docblock comment exists or if it's empty
			if (empty($reflection->getDocComment())) {
				return;
			}
			
			try {
				// Create a lexer and parser and parse the class
				$lexer = new Lexer($reflection->getDocComment());
				$parser = new Parser($lexer, $this->configuration, $reflection);
				
				// Parse the class and store the result
				$result = $parser->parse();
			} catch (LexerException|ParserException $e) {
				// Wrap parsing exceptions in a more specific exception type
				throw new AnnotationReaderException(
					"Failed to parse class annotations for '{$reflection->getName()}': {$e->getMessage()}",
					$e->getCode(),
					$e
				);
			}
		}
		
		/**
		 * Parse annotations for properties or methods and update the result array.
		 * @param array<\ReflectionProperty|\ReflectionMethod> $items An array of ReflectionProperty or ReflectionMethod objects.
		 * @param array<string, AnnotationCollection> $result The result array to be updated with parsed annotations.
		 * @param \ReflectionClass<object>|null $reflection The reflection class
		 * @return void
		 * @throws AnnotationReaderException
		 */
		protected function parseAnnotations(array $items, array &$result, ?\ReflectionClass $reflection = null): void {
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
					$parser = new Parser($lexer, $this->configuration, $reflection);
					$annotations = $parser->parse();
					
					// Skip if there are no annotations
					if ($annotations->isEmpty()) {
						continue;
					}
					
					// Add the annotations to the result array
					$result[$item->getName()] = $annotations;
				} catch (ParserException|LexerException $e) {
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
		 * @param \ReflectionClass<object> $reflection
		 * @return AnnotationSet
		 * @throws AnnotationReaderException
		 */
		protected function readAllObjectAnnotations(\ReflectionClass $reflection): array {
			// Setup array which will receive the parse results
			$result = [
				'class'      => new AnnotationCollection(),
				'properties' => [],
				'methods'    => []
			];
			
			// Parse the annotations and return result
			$this->parseClassAnnotations($reflection, $result['class']);
			$this->parseAnnotations($reflection->getProperties(), $result['properties'], $reflection);
			$this->parseAnnotations($reflection->getMethods(), $result['methods'], $reflection);
			return $result;
		}
		
		/**
		 * Retrieve all annotations for a given class, caching the results for performance.
		 * @param class-string|object $class The fully qualified class name to get annotations for.
		 * @return AnnotationSet An array containing all annotations for the class, its properties, and its methods.
		 * @throws AnnotationReaderException
		 */
		protected function getAllObjectAnnotations(string|object $class): array {
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
				if ($this->writeCacheToFile($cacheFilename, $annotations)) {
					// Update manifests for all annotation types present in this cache entry
					$this->updateManifests($cacheFilename, $annotations);
				}
				
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
		
		/**
		 * Transforms an annotation class name to a manifest filename using an MD5 hash.
		 * Hashing keeps filenames short and filesystem-safe while remaining deterministic:
		 * the CLI can reconstruct the filename from the annotation class name without
		 * needing a lookup table.
		 * @param string $annotationClass Fully qualified annotation class name
		 * @return string
		 */
		protected function generateManifestFilename(string $annotationClass): string {
			return md5($annotationClass) . '.manifest';
		}
		
		/**
		 * Acquires an exclusive lock on the companion lock file for a given manifest.
		 *
		 * Both updateManifests() and clearCacheByAnnotationClass() acquire LOCK_EX
		 * on this file before reading or writing the manifest, serializing the entire
		 * read-modify-write cycle across processes. This prevents a rewrite from
		 * silently discarding a concurrent append, and vice versa.
		 *
		 * Lock files are never deleted. Deleting a lock file creates a race where a
		 * process waiting on flock() and a new process calling fopen() can end up
		 * locking different inodes, silently breaking the synchronization guarantee.
		 * The storage cost of keeping them is negligible.
		 *
		 * The caller must release the lock with flock($handle, LOCK_UN) and close
		 * the handle when the manifest operation is complete.
		 *
		 * Returns false if the lock file cannot be opened or locked.
		 * @param string $manifestPath Full path to the manifest file (not the lock file)
		 * @return resource|false An open file handle with LOCK_EX held, or false on failure
		 */
		protected function acquireManifestLock(string $manifestPath): mixed {
			$lockPath = $manifestPath . '.lock';
			
			// Open in 'c' mode: creates the file if missing, does not truncate if existing.
			// 'w' would truncate on open, which is unnecessary churn.
			$handle = fopen($lockPath, 'c');
			
			// If we couldn't open the file, return with failure
			if ($handle === false) {
				return false;
			}
			
			// Block until we are the sole writer for this manifest
			if (!flock($handle, LOCK_EX)) {
				fclose($handle);
				return false;
			}
			
			return $handle;
		}
		
		/**
		 * Reads a manifest file without acquiring a lock.
		 *
		 * This must only be called while the caller already holds the exclusive lock
		 * obtained from acquireManifestLock(). The lock guarantees no concurrent writer
		 * is modifying the file, so no additional locking is needed here.
		 *
		 * Returns null when the file cannot be read (e.g. it does not yet exist, which
		 * is a valid state for a manifest that has never been written).
		 * @param string $manifestPath Full path to the manifest file
		 * @return list<string>|null Lines in the manifest, or null on failure
		 */
		protected function readManifestLocked(string $manifestPath): ?array {
			$contents = @file_get_contents($manifestPath);
			
			if ($contents === false) {
				return null;
			}
			
			// Split on newlines and drop empty lines produced by the trailing newline
			// that each entry write appends
			return array_values(array_filter(explode("\n", $contents), static fn(string $l) => $l !== ''));
		}
		
		/**
		 * Atomically replaces a manifest file by writing to a per-process temp file
		 * and then calling rename(), which is atomic on POSIX filesystems.
		 *
		 * Must be called while the caller holds the exclusive lock for this manifest
		 * (from acquireManifestLock()). The lock ensures no concurrent appender can
		 * write to the old inode between our write and our rename, so no entries are lost.
		 *
		 * The temp file is scoped to the current PID to avoid collisions under
		 * parallel requests that somehow bypass the lock (e.g. on non-POSIX systems
		 * where flock is advisory-only).
		 * @param string $manifestPath Full path to the manifest file to replace
		 * @param list<string> $entries Lines to write; a trailing newline is appended to each
		 * @return void
		 */
		protected function writeManifestAtomic(string $manifestPath, array $entries): void {
			$tmpPath = $manifestPath . '.' . getmypid() . '.tmp';
			
			if (file_put_contents($tmpPath, implode("\n", $entries) . "\n") === false) {
				// Temp write failed; leave the original manifest untouched
				return;
			}
			
			// rename() is atomic on POSIX: readers see old or new, never partial
			if (!rename($tmpPath, $manifestPath)) {
				@unlink($tmpPath);
			}
		}
		
		/**
		 * Adds a cache filename to the manifest for each annotation class present
		 * in the given AnnotationSet. Called every time a cache file is written so
		 * that manifests stay in sync with the cache directory.
		 *
		 * Each annotation type gets its own manifest file (keyed by MD5 of the
		 * annotation class name), so CLI commands can clear cache files for a
		 * specific annotation type without touching unrelated entries.
		 *
		 * Each manifest is updated under an exclusive lock that covers the full
		 * read-check-write cycle, preventing duplicate entries when multiple
		 * processes regenerate the same class cache simultaneously.
		 * @param string $cacheFilename The cache filename to add to each manifest
		 * @param AnnotationSet $annotations The full annotation set for the class
		 * @return void
		 */
		protected function updateManifests(string $cacheFilename, array $annotations): void {
			// Collect all unique annotation class names present in this cache entry.
			// Using a map keyed by class name avoids redundant manifest updates when
			// the same annotation type appears on multiple methods or properties.
			$annotationClasses = [];
			
			// Collect annotation classes from class-level annotations
			foreach ($annotations['class'] as $annotation) {
				$annotationClasses[get_class($annotation)] = true;
			}
			
			// Collect annotation classes from method-level annotations
			foreach ($annotations['methods'] as $collection) {
				foreach ($collection as $annotation) {
					$annotationClasses[get_class($annotation)] = true;
				}
			}
			
			// Collect annotation classes from property-level annotations
			foreach ($annotations['properties'] as $collection) {
				foreach ($collection as $annotation) {
					$annotationClasses[get_class($annotation)] = true;
				}
			}
			
			foreach (array_keys($annotationClasses) as $annotationClass) {
				$manifestFilename = $this->generateManifestFilename($annotationClass);
				$manifestPath = $this->annotationCachePath . DIRECTORY_SEPARATOR . $manifestFilename;
				
				// Acquire an exclusive lock before reading the manifest so that the
				// duplicate check and the write are a single atomic operation.
				// Without this, two processes regenerating the same class simultaneously
				// could both read an empty manifest and both append, producing duplicates.
				$lockHandle = $this->acquireManifestLock($manifestPath);
				
				if ($lockHandle === false) {
					continue;
				}
				
				// Read current entries to check for duplicates
				$existing = $this->readManifestLocked($manifestPath) ?? [];
				
				if (!in_array($cacheFilename, $existing, true)) {
					// Entry is not yet present — append it under the lock we already hold.
					// FILE_APPEND + the existing LOCK_EX (held via the .lock file) is safe;
					// we write directly rather than going through writeManifestAtomic because
					// an append to the existing inode is sufficient here and avoids the rename.
					file_put_contents($manifestPath, $cacheFilename . "\n", FILE_APPEND);
				}
				
				flock($lockHandle, LOCK_UN);
				fclose($lockHandle);
			}
		}
	}