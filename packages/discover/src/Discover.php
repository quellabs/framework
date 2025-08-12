<?php
	
	namespace Quellabs\Discover;
	
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Discover\Scanner\ScannerInterface;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\Discovery\ProviderDefinition;
	
	class Discover {
		
		/**
		 * @var array<ScannerInterface>
		 */
		protected array $scanners = [];
		
		/**
		 * @var array<string, ProviderDefinition> Provider definitions indexed by unique keys
		 */
		protected array $providerDefinitions = [];
		
		/**
		 * @var array Map of instantiated providers by definition key
		 */
		protected array $instantiatedProviders = [];
		
		/**
		 * Discover providers using all registered scanners
		 * @return self
		 */
		public function discover(): self {
			// Clear any previously discovered providers to start fresh
			$this->clearProviders();
			
			// Iterate through each registered scanner to discover providers
			foreach ($this->scanners as $scanner) {
				foreach ($scanner->scan() as $definition) {
					$this->addProviderDefinition($definition);
				}
			}
			
			// Return self to enable method chaining
			return $this;
		}
		
		/**
		 * Check if any providers were discovered
		 * @return bool True if providers have been discovered, false if no discovery has occurred
		 */
		public function hasProviders(): bool {
			return !empty($this->providerDefinitions);
		}
		
		/**
		 * Returns the raw provider definitions array containing metadata for all
		 * discovered providers. Each definition includes class name, family, configuration
		 * file path, and other metadata gathered during the discovery process.
		 * This is useful for debugging, caching, or external analysis of discovered providers.
		 * @return array<ProviderDefinition> Array of provider definitions
		 */
		public function getDefinitions(): array {
			return array_values($this->providerDefinitions);
		}
		
		/**
		 * Get a specific provider definition by class name
		 * @param string $className The fully qualified class name of the provider
		 * @return ProviderDefinition|null The provider definition if found, null if not found
		 */
		public function getDefinition(string $className): ?ProviderDefinition {
			foreach ($this->providerDefinitions as $definition) {
				if ($definition->className === $className) {
					return $definition;
				}
			}
			
			return null;
		}
		
		/**
		 * Retrieve a specific provider instance by class name
		 * @template T of ProviderInterface
		 * @param class-string<T> $className The fully qualified class name of the provider to retrieve
		 * @return T|null The provider instance if found, null otherwise
		 */
		public function get(string $className) {
			// Iterate through all discovered provider definitions.
			// Each definition contains metadata gathered during discovery without instantiation.
			foreach ($this->providerDefinitions as $definitionKey => $definition) {
				if ($definition->className === $className) {
					// Attempt to get or create a provider instance from the definition
					// Uses lazy instantiation helper that handles caching and reconstruction
					return $this->getOrInstantiateProvider($definitionKey, $definition);
				}
			}
			
			return null;
		}
		
		/**
		 * Check if a provider with the specified class exists in discovered definitions
		 * @param string $className The fully qualified class name of the provider to check
		 * @return bool True if a provider definition exists for the class, false otherwise
		 */
		public function exists(string $className): bool {
			// Search through all discovered provider definitions for a matching class name
			foreach ($this->providerDefinitions as $definition) {
				if ($definition->className === $className) {
					return true;
				}
			}
			
			// No matching provider definition found
			return false;
		}
		
		/**
		 * Get all providers (instantiates all definitions
		 * WARNING: This will instantiate ALL providers, which can be expensive
		 * @return \Generator
		 */
		public function getProviders(): \Generator {
			// Iterate through every registered provider definition
			foreach ($this->providerDefinitions as $definitionKey => $definition) {
				// Attempt to get or create a provider instance from the definition
				// Uses lazy instantiation helper that handles caching and reconstruction
				$provider = $this->getOrInstantiateProvider($definitionKey, $definition);
				
				// Only include successfully instantiated providers in the result
				// Filters out any providers that failed to instantiate properly
				if ($provider) {
					yield $provider;
				}
			}
		}

		/**
		 * Clear all providers and definitions
		 * @return self
		 */
		public function clearProviders(): self {
			$this->providerDefinitions = [];
			$this->instantiatedProviders = [];
			return $this;
		}
		
		/**
		 * Add a scanner
		 * @param ScannerInterface $scanner
		 * @return self
		 */
		public function addScanner(ScannerInterface $scanner): self {
			$this->scanners[] = $scanner;
			return $this;
		}
		
		/**
		 * Get all available provider types (no instantiation needed)
		 * @return array<string> Array of unique provider types
		 */
		public function getProviderTypes(): array {
			$types = [];

			foreach ($this->providerDefinitions as $definition) {
				// Check if this family type hasn't been added yet to maintain uniqueness
				if (!in_array($definition->family, $types)) {
					// Add the new family type to the collection
					$types[] = $definition->family;
				}
			}
			
			return $types;
		}
		
		/**
		 * Get metadata from all providers without instantiation
		 * @return array<string, array> Provider metadata indexed by class name
		 */
		public function getAllProviderMetadata(): array {
			$metadata = [];
			
			foreach ($this->providerDefinitions as $definition) {
				// Store metadata using class name as key for easy lookup
				// This allows quick access to provider metadata without creating instances
				$metadata[$definition->className] = $definition->metadata;
			}
			
			return $metadata;
		}
		
		/**
		 * Find providers by metadata using a filter function (with lazy instantiation)
		 * @param callable $metadataFilter Function that receives metadata and returns bool
		 * @return \Generator
		 */
		public function findProvidersByMetadata(callable $metadataFilter): \Generator {
			foreach ($this->providerDefinitions as $definitionKey => $definition) {
				// Apply the metadata filter function to determine if this provider matches
				if ($metadataFilter($definition->metadata)) {
					// Lazily instantiate the provider only when metadata filter passes
					// This avoids creating provider instances for non-matching definitions
					$provider = $this->getOrInstantiateProvider($definitionKey, $definition);
					
					// Add provider to results only if instantiation succeeded
					if ($provider) {
						yield $provider;
					}
				}
			}
		}
		
		/**
		 * Find all providers of a specific family type (with lazy instantiation)
		 * @param string $family The family type to filter by
		 * @return \Generator
		 */
		public function findProvidersByFamily(string $family): \Generator {
			foreach ($this->providerDefinitions as $definitionKey => $definition) {
				// Check if the current definition belongs to the requested family
				if ($definition->belongsToFamily($family)) {
					// Lazily instantiate the provider only when it matches the family criteria
					// This defers object creation until we know the provider is needed
					$provider = $this->getOrInstantiateProvider($definitionKey, $definition);
					
					// Add the provider to results only if instantiation was successful
					if ($provider) {
						yield $provider;
					}
				}
			}
		}
		
		/**
		 * Find providers that match a specific family and metadata filter (with lazy instantiation)
		 * @param string $family The family to filter by
		 * @param callable $metadataFilter Function that receives metadata and returns bool
		 * @return \Generator
		 */
		public function findProvidersByFamilyAndMetadata(string $family, callable $metadataFilter): \Generator {
			foreach ($this->providerDefinitions as $definitionKey => $definition) {
				if ($definition->belongsToFamily($family) && $metadataFilter($definition->metadata)) {
					// Lazily instantiate the provider only when it matches both criteria.
					// This avoids creating unnecessary provider instances for non-matching definitions.
					$provider = $this->getOrInstantiateProvider($definitionKey, $definition);
					
					// Only add successfully instantiated providers to the result
					if ($provider) {
						yield $provider;
					}
				}
			}
		}
		
		/**
		 * Export current provider definitions for caching
		 * @return array Cacheable provider definitions
		 */
		public function exportForCache(): array {
			// Initialize cache data structure with timestamp and empty providers array
			$cacheData = [
				'timestamp' => time(), // Record when this cache was created
				'providers' => []      // Will hold provider definitions grouped by family
			];
			
			// Iterate through all registered provider definitions
			foreach ($this->providerDefinitions as $definition) {
				// Extract the family name to group related providers together
				$family = $definition->family;
				
				// Initialize the family array if it doesn't exist yet
				if (!isset($cacheData['providers'][$family])) {
					$cacheData['providers'][$family] = [];
				}
				
				// Convert the definition to array format and add to the appropriate family group
				$cacheData['providers'][$family][] = $definition->toArray();
			}
			
			// Return the structured cache data ready for serialization/storage
			return $cacheData;
		}
		
		/**
		 * Import provider definitions from cache
		 * @param array $cacheData Previously exported provider data
		 * @return self
		 */
		public function importDefinitionsFromCache(array $cacheData): self {
			// Clear all existing providers before importing from cache
			$this->clearProviders();
			
			// Validate that cache data contains the expected 'providers' key and is an array
			if (!isset($cacheData['providers']) || !is_array($cacheData['providers'])) {
				// Return early if cache data is invalid or missing providers
				return $this;
			}
			
			// Iterate through each provider family in the cache data
			foreach ($cacheData['providers'] as $familyProviders) {
				// Process each provider within the current family
				foreach ($familyProviders as $providerData) {
					try {
						// Reconstruct the provider definition from the cached array data
						$definition = ProviderDefinition::fromArray($providerData);
						
						// Add the reconstructed definition to the current instance
						$this->addProviderDefinition($definition);
					} catch (\InvalidArgumentException $e) {
						// Skip invalid cached definitions and continue processing others
						// This ensures corrupt or incompatible cache entries don't break the entire import
						continue;
					}
				}
			}
			
			// Return self to allow method chaining
			return $this;
		}

		/**
		 * Get or instantiate a provider from its definition
		 * @param string $definitionKey Unique key for the provider definition
		 * @param ProviderDefinition $definition Provider definition
		 * @return ProviderInterface|null
		 */
		protected function getOrInstantiateProvider(string $definitionKey, ProviderDefinition $definition): ?ProviderInterface {
			// Check if we already have a cached instance for this provider definition
			// This implements lazy instantiation - providers are only created when first needed
			if (isset($this->instantiatedProviders[$definitionKey])) {
				return $this->instantiatedProviders[$definitionKey];
			}
			
			// No cached instance exists, so create a new provider from the definition data
			// Delegate the complex instantiation logic to the specialized reconstruction method
			$provider = $this->instantiateProvider($definition);
			
			// If instantiation was successful, cache the new provider instance
			if ($provider) {
				// Store in cache using the definition key for future lookups
				// This ensures subsequent calls for the same provider return the same instance
				$this->instantiatedProviders[$definitionKey] = $provider;
			}
			
			// Return either the newly created provider or null if instantiation failed
			return $provider;
		}
		
		/**
		 * Add a provider definition
		 * @param ProviderDefinition $definition
		 * @return void
		 */
		protected function addProviderDefinition(ProviderDefinition $definition): void {
			// Extract the unique key from the provider definition
			$key = $definition->getKey();
			
			// Skip if already exists - prevents duplicate provider definitions
			// This ensures we don't overwrite existing providers with the same key
			if (isset($this->providerDefinitions[$key])) {
				return;
			}
			
			// Store the provider definition using its key for fast lookup
			// This allows efficient retrieval of providers by their unique identifier
			$this->providerDefinitions[$key] = $definition;
		}
		
		/**
		 * Instantiate and configure a provider from definition data
		 * Creates a new provider instance, loads its configuration from file (if specified),
		 * merges it with defaults, and applies the final configuration to the provider.
		 * @param ProviderDefinition $definition Provider definition
		 * @return ProviderInterface|null Successfully instantiated and configured provider or null on failure
		 */
		protected function instantiateProvider(ProviderDefinition $definition): ?ProviderInterface {
			// Extract the class name from the provider definition
			$className = $definition->className;
			
			// Verify that the class exists before attempting to instantiate
			if (!class_exists($className)) {
				return null;
			}
			
			try {
				// Create a new instance of the provider class
				$provider = new $className();
				
				// Ensure the instantiated object implements the required interface
				if (!$provider instanceof ProviderInterface) {
					return null;
				}
				
				// Load configuration from the file if specified in the definition
				$loadedConfig = $this->loadConfigFiles($definition->configFiles);
				
				// Merge default configuration with loaded config (loaded config takes precedence)
				$finalConfig = array_merge($definition->defaults, $loadedConfig);
				
				// Apply the final merged configuration to the provider
				$provider->setConfig($finalConfig);
				
				// Return the fully configured provider instance
				return $provider;
				
			} catch (\Throwable $e) {
				// Log error
				error_log("Couldn't instantiate provider {$className}: {$e->getMessage()}");

				// Return null if any exception occurs during instantiation or configuration
				return null;
			}
		}
		
		/**
		 * Loads a configuration file and returns its contents as an array.
		 * @param array $configFiles List of config files to load
		 * @return array The configuration array from the file, or empty array if the file doesn't exist
		 */
		protected function loadConfigFiles(array $configFiles): array {
			// Return empty config when no file given
			if (empty($configFiles)) {
				return [];
			}
			
			// Get the project's root directory
			$rootDir = ComposerUtils::getProjectRoot();

			// Fetch and merge all given config files
			$result = [];
			
			foreach($configFiles as $configFile) {
				// Build the absolute path to the configuration file
				$completeDir = $rootDir . DIRECTORY_SEPARATOR . $configFile;
				
				// Make sure the file exists before attempting to load it
				if (!file_exists($completeDir)) {
					continue;
				}
				
				// Include the file and return its contents
				// This works because PHP's include statement returns the result of the included file,
				// which should be an array if the file contains 'return []' or similar
				$result = array_merge($result, include $completeDir);
			}
			
			return $result;
		}
	}