<?php
	
	namespace Quellabs\AnnotationReader\Reader;
	
	use Quellabs\AnnotationReader\Annotation\AnnotationFactory;
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\AnnotationReaderInterface;
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\AnnotationReader\Configuration;
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
	abstract class CachedAnnotationReader implements AnnotationReaderInterface {
		
		/**
		 * @var bool Write cache files yes/no
		 */
		protected bool $useCache;
		
		/**
		 * @var string Directory to store cache files in
		 */
		protected string $annotationCachePath;
		
		/**
		 * @var bool When true, cache files are re-validated against source mtime on every read
		 */
		protected bool $debugMode;
		
		/**
		 * @var array<string, mixed>
		 */
		protected array $configuration;
		
		/**
		 * @var array<string, AnnotationSet>
		 */
		protected array $cached_annotations;
		
		/**
		 * Initializes the shared cache settings.
		 * @param Configuration $configuration
		 * @return void
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
				return (new Parser(new Lexer($string), $this->configuration))->parse();
			} catch (LexerException|ParserException $e) {
				throw new AnnotationReaderException($e->getMessage(), $e->getCode(), $e);
			}
		}
		
		/**
		 * Deletes every cache file that contains at least one annotation of the
		 * given class. Used by CLI cache-clear commands to invalidate only the
		 * cache entries relevant to a specific subsystem (e.g. routes:clear-cache
		 * passes Route::class).
		 *
		 * Cache files are keyed per class and can hold a mix of annotation types,
		 * so this reads each one back and checks its contents directly rather than
		 * relying on a separate index — the cache directory is small and this is a
		 * rare, manual operation, not something on the request path.
		 * @param string $annotationClass Fully qualified annotation class name, e.g. Route::class
		 * @return void
		 */
		public function clearCacheByAnnotationClass(string $annotationClass): void {
			foreach ($this->cached_annotations as $cacheFilename => $annotations) {
				if ($this->annotationSetHasClass($annotations, $annotationClass)) {
					unset($this->cached_annotations[$cacheFilename]);
				}
			}
			if (!is_dir($this->annotationCachePath)) {
				return;
			}

			$files = glob($this->annotationCachePath . DIRECTORY_SEPARATOR . $this->cacheFilePattern());

			if ($files === false) {
				return;
			}

			foreach ($files as $cachePath) {
				if (!$this->ownsCacheFile($cachePath)) {
					continue;
				}
				$annotations = $this->readCacheFromFile(basename($cachePath));

				if (
					($annotations !== null && $this->annotationSetHasClass($annotations, $annotationClass)) ||
					($annotations === null && $this->legacyCacheFileHasClass($cachePath, $annotationClass))
				) {
					@unlink($cachePath);
				}
			}
		}

		/**
		 * Checks whether an AnnotationSet contains at least one instance of the given
		 * annotation class, at the class, method, or property level.
		 * @param AnnotationSet $annotations
		 * @param string $annotationClass Fully qualified annotation class name
		 * @return bool
		 */
		private function annotationSetHasClass(array $annotations, string $annotationClass): bool {
			if (!$annotations['class']->filter(static fn($a) => $a instanceof $annotationClass)->isEmpty()) {
				return true;
			}

			foreach ([...$annotations['methods'], ...$annotations['properties']] as $collection) {
				if (!$collection->filter(static fn($a) => $a instanceof $annotationClass)->isEmpty()) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Deletes all annotation cache files, so the next request rebuilds
		 * everything from scratch. Used by the catch-all cache clear CLI command.
		 * @return void
		 */
		public function clearAllCaches(): void {
			$this->cached_annotations = [];
			if (!is_dir($this->annotationCachePath)) {
				return;
			}

			$files = glob($this->annotationCachePath . DIRECTORY_SEPARATOR . $this->cacheFilePattern());

			if ($files === false) {
				return;
			}

			foreach ($files as $file) {
				if (is_file($file) && $this->ownsCacheFile($file)) {
					unlink($file);
				}
			}
		}

		/**
		 * Checks old cache files before removing entries for one annotation class.
		 * @param string $cachePath
		 * @param string $annotationClass
		 * @return bool
		 */
		private function legacyCacheFileHasClass(string $cachePath, string $annotationClass): bool {
			$contents = file_get_contents($cachePath);
			if ($contents === false) {
				return false;
			}
			$data = json_decode($contents, true);
			if (!is_array($data)) {
				return false;
			}
			$collections = [$data['class'] ?? []];
			foreach (['methods', 'properties'] as $section) {
				if (!is_array($data[$section] ?? null)) {
					continue;
				}
				foreach ($data[$section] as $collection) {
					$collections[] = $collection;
				}
			}
			foreach ($collections as $collection) {
				if (!is_array($collection)) {
					continue;
				}
				foreach ($collection as $entry) {
					if (is_array($entry) && is_string($entry['class'] ?? null) && is_a($entry['class'], $annotationClass, true)) {
						return true;
					}
				}
			}
			return false;
		}

		/**
		 * @return string
		 */
		protected function cacheFilePattern(): string {
			return '*.cache';
		}

		/**
		 * @param string $file
		 * @return bool
		 */
		protected function ownsCacheFile(string $file): bool {
			return !str_ends_with($file, '.attributes.cache');
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
		 * Each annotation stores its class and constructor parameters as plain data.
		 * @param AnnotationCollection $collection
		 * @return list<SerializedAnnotation>
		 */
		protected function serializeCollection(AnnotationCollection $collection): array {
			$result = [];
			
			/**
			 * @var AnnotationInterface $annotation
			 */
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
		 * Rebuilds annotations through the constructor appropriate to each class.
		 * @param array<mixed> $data
		 * @return AnnotationCollection
		 */
		protected function deserializeCollection(array $data): AnnotationCollection {
			/**
			 * @var array<int, AnnotationInterface> $annotations
			 */
			$annotations = [];
			
			foreach ($data as $entry) {
				// Skip corrupt entries
				if (!is_array($entry)) {
					continue;
				}
				
				$class = $entry['class'];
				
				// Skip entries with a missing or non-AnnotationInterface class
				if (
					!is_string($class) ||
					!class_exists($class) ||
					!is_a($class, AnnotationInterface::class, true)
				) {
					continue;
				}
				
				if (!is_array($entry['parameters'] ?? null)) {
					return new AnnotationCollection();
				}
				$annotations[] = AnnotationFactory::create($class, $entry['parameters']);
			}
			
			return new AnnotationCollection($annotations);
		}
		
		/**
		 * Reads from cache
		 * @param string $cacheFilename
		 * @return AnnotationSet|null
		 */
		protected function readCacheFromFile(string $cacheFilename): ?array {
			$cachePath = "{$this->annotationCachePath}/{$cacheFilename}";
			$fileContents = file_get_contents($cachePath);
			
			if ($fileContents === false) {
				return null;
			}
			
			$decoded = json_decode($fileContents, true);
			
			// Version 2 stores parameters for natural constructors; older entries must rebuild.
			if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || ($decoded['version'] ?? null) !== 2) {
				return null;
			}
			
			if (!is_array($decoded['class'] ?? null) || !is_array($decoded['methods'] ?? null) || !is_array($decoded['properties'] ?? null)) {
				return null;
			}
			$classData = $decoded['class'];
			$methods = $decoded['methods'];
			$properties = $decoded['properties'];
			if (!$this->validSerializedCollection($classData)) {
				return null;
			}
			foreach ([$methods, $properties] as $section) {
				foreach ($section as $collectionData) {
					if (!$this->validSerializedCollection($collectionData)) {
						return null;
					}
				}
			}
			
			// Reconstruct collections, invoking each annotation's constructor
			// so typed properties are properly initialized
			$methodCollections = [];
			$propertyCollections = [];
			
			try {
				foreach ($methods as $methodName => $collectionData) {
					/**
					 * @var list<SerializedAnnotation> $collectionData
					 */
					$methodCollections[$methodName] = $this->deserializeCollection($collectionData);
				}
				
				foreach ($properties as $propertyName => $collectionData) {
					/**
					 * @var list<SerializedAnnotation> $collectionData
					 */
					$propertyCollections[$propertyName] = $this->deserializeCollection($collectionData);
				}
				
				/**
				 * @var list<SerializedAnnotation> $classData
				 */
				/**
				 * @var AnnotationSet $result
				 */
				$result = [
					'class'      => $this->deserializeCollection($classData),
					'methods'    => $methodCollections,
					'properties' => $propertyCollections,
				];
				
				return $result;
			} catch (\Throwable $e) {
				return null;
			}
		}

		/**
		 * Validates a collection before reconstructing any cached objects.
		 * @param mixed $data
		 * @return bool
		 */
		private function validSerializedCollection(mixed $data): bool {
			if (!is_array($data)) {
				return false;
			}
			foreach ($data as $entry) {
				if (!is_array($entry) || !is_string($entry['class'] ?? null) || !is_array($entry['parameters'] ?? null)) {
					return false;
				}
				if (!is_a($entry['class'], AnnotationInterface::class, true)) {
					return false;
				}
			}
			return true;
		}
		
		/**
		 * Updates the cache
		 * @param string $cacheFilename
		 * @param AnnotationSet $annotations
		 * @return int|false
		 */
		protected function writeCacheToFile(string $cacheFilename, array $annotations): int|false {
			// The triple is_dir/mkdir/is_dir pattern handles the TOCTOU race: if two
			// processes both see the directory missing and both call mkdir(), the second
			// fails but the final is_dir() confirms success via the first.
			if (!is_dir($this->annotationCachePath) && !mkdir($this->annotationCachePath, 0755, true) && !is_dir($this->annotationCachePath)) {
				return false;
			}
			
			// JSON rather than serialize(): annotations are stored as plain data
			// (class name + parameters) and reconstructed via their constructors on read.
			// serialize/unserialize bypasses __construct, leaving typed properties uninitialized.
			$methodData = [];
			$propertyData = [];
			
			foreach ($annotations['methods'] as $methodName => $collection) {
				$methodData[$methodName] = $this->serializeCollection($collection);
			}
			
			foreach ($annotations['properties'] as $propertyName => $collection) {
				$propertyData[$propertyName] = $this->serializeCollection($collection);
			}
			
			// Create the payload
			$data = [
				'version'    => 2,
				'class'      => $this->serializeCollection($annotations['class']),
				'methods'    => $methodData,
				'properties' => $propertyData,
			];
			if (!$this->isCacheSafe($data)) {
				return false;
			}
			try {
				$payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
			} catch (\JsonException $e) {
				return false;
			}
			
			$cachePath = $this->annotationCachePath . DIRECTORY_SEPARATOR . $cacheFilename;
			
			// Write to a PID-scoped temp file and rename() atomically into place,
			// so a concurrent reader never observes a partially-written JSON payload.
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
		 * Rejects values JSON cannot restore to the same constructor arguments.
		 * @param mixed $value
		 * @return bool
		 */
		private function isCacheSafe(mixed $value): bool {
			if (is_array($value)) {
				foreach ($value as $child) {
					if (!$this->isCacheSafe($child)) {
						return false;
					}
				}
				return true;
			}
			return $value === null || is_string($value) || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value));
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
			// Fetch the cache path
			$cachePath = "{$this->annotationCachePath}/{$cacheFilename}";
			
			if (!file_exists($cachePath) || !is_readable($cachePath)) {
				return false;
			}
			
			// In production mode, existence is sufficient — skipping filemtime() saves
			// two stat calls per class per request.
			if (!$this->debugMode) {
				return true;
			}
			
			// In debug mode, check mtime so annotation changes are picked up without
			// a manual cache clear.
			$fileName = $reflection->getFileName();
			
			if ($fileName === false) {
				return false;
			}
			
			return filemtime($fileName) <= filemtime($cachePath);
		}
		
		/**
		 * Reads source metadata for a reflected class.
		 * @param \ReflectionClass<object> $reflection
		 * @return AnnotationSet
		 */
		abstract protected function readAllObjectAnnotations(\ReflectionClass $reflection): array;

		/**
		 * Retrieve all annotations for a given class, caching the results for performance.
		 * @param class-string|object $class The fully qualified class name to get annotations for.
		 * @return AnnotationSet An array containing all annotations for the class, its properties, and its methods.
		 * @throws AnnotationReaderException
		 */
		protected function getAllObjectAnnotations(string|object $class): array {
			try {
				$reflection = new \ReflectionClass($class);
				$className = $reflection->getName();
				$cacheFilename = $this->generateCacheFilename($className);
				
				// Return immediately if already in the memory cache
				if (isset($this->cached_annotations[$cacheFilename])) {
					return $this->cached_annotations[$cacheFilename];
				}
				
				// Caching disabled — parse directly but still populate memory cache
				if (!$this->useCache || empty($this->annotationCachePath)) {
					$this->cached_annotations[$cacheFilename] = $this->readAllObjectAnnotations($reflection);
					return $this->cached_annotations[$cacheFilename];
				}
				
				// Check if a valid on-disk cache file exists
				if ($this->cacheValid($cacheFilename, $reflection)) {
					$cachedData = $this->readCacheFromFile($cacheFilename);
					
					if ($cachedData !== null) {
						$this->cached_annotations[$cacheFilename] = $cachedData;
						return $this->cached_annotations[$cacheFilename];
					}
				}
				
				// No valid cache — parse from source
				$annotations = $this->readAllObjectAnnotations($reflection);

				// Write to disk cache for subsequent requests
				$this->writeCacheToFile($cacheFilename, $annotations);

				// Populate memory cache for this request lifecycle
				$this->cached_annotations[$cacheFilename] = $annotations;
				
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
