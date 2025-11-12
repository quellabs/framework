<?php
	
	namespace Quellabs\Discover;
	
	use Quellabs\Discover\ProviderQuery\ProviderQuery;
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
		 * @var array<string, ProviderDefinition> Provider definitions indexed by class name
		 */
		protected array $providerDefinitionsByClass = [];
		
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
		 * Get a specific provider definition by class name
		 * @param string $className The fully qualified class name of the provider
		 * @return ProviderDefinition|null The provider definition if found, null if not found
		 */
		public function getDefinition(string $className): ?ProviderDefinition {
			return $this->providerDefinitionsByClass[$className] ?? null;
		}
		
		/**
		 * Retrieve a specific provider instance by class name
		 * @template T of ProviderInterface
		 * @param class-string<T> $className The fully qualified class name of the provider to retrieve
		 * @return T|null The provider instance if found, null otherwise
		 */
		public function get(string $className) {
			// Use indexed lookup for O(1) performance
			$definition = $this->providerDefinitionsByClass[$className] ?? null;
			
			// Return null if the definition does not exist
			if ($definition === null) {
				return null;
			}
			
			// Get the definition key for cache lookup
			$definitionKey = $definition->getKey();
			
			// Attempt to get or create a provider instance from the definition
			// Uses lazy instantiation helper that handles caching and reconstruction
			return $this->getOrInstantiateProvider($definitionKey, $definition);
		}
		
		/**
		 * Check if a provider with the specified class exists in discovered definitions
		 * @param string $className The fully qualified class name of the provider to check
		 * @return bool True if a provider definition exists for the class, false otherwise
		 */
		public function exists(string $className): bool {
			return isset($this->providerDefinitionsByClass[$className]);
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
			$this->providerDefinitionsByClass = [];
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
		 * Create a new provider query builder for fluent filtering
		 * @return ProviderQuery A query builder for filtering and retrieving providers
		 */
		public function findProviders(): ProviderQuery {
			// Create closure that respects the instantiation cache
			// This ensures providers are only instantiated once and reused across queries
			$instantiator = function(ProviderDefinition $def) {
				// Get the unique key for this provider definition
				// This key is used to check if we already have a cached instance
				$key = $def->getKey();
				
				// Use the lazy instantiation helper that checks cache first
				// Returns cached instance if available, otherwise creates and caches new one
				return $this->getOrInstantiateProvider($key, $def);
			};
			
			// Create and return the query builder with the caching instantiator
			// Pass all provider definitions so the query can filter them
			return new ProviderQuery($instantiator, $this->providerDefinitions);
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
			
			// Also index by class name for O(1) lookup by class
			// Enables fast retrieval when searching for specific provider classes
			$this->providerDefinitionsByClass[$definition->className] = $definition;
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