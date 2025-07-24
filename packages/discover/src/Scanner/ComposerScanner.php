<?php
	
	namespace Quellabs\Discover\Scanner;
	
	use Quellabs\Discover\Utilities\ComposerInstalledLoader;
	use Quellabs\Discover\Utilities\ComposerJsonLoader;
	use Quellabs\Discover\Utilities\ProviderValidator;
	use InvalidArgumentException;
	use Quellabs\Contracts\Discovery\ProviderDefinition;
	use Psr\Log\LoggerInterface;
	use Psr\Log\NullLogger;
	
	/**
	 * Scans composer.json files to discover service providers
	 * Includes static file caching to avoid re-reading same files
	 */
	class ComposerScanner implements ScannerInterface {
		
		/**
		 * Constants
		 */
		public const string DEFAULT_DISCOVERY_SECTION = 'discover';
		
		/**
		 * The key to look for in composer.json extra section
		 * This also serves as the family name for discovered providers
		 * @var string|null
		 */
		protected readonly ?string $familyName;
		
		/**
		 * The top-level key in composer.json's extra section that contains discovery configuration.
		 * Defaults to 'discover' but can be customized to use a different section name.
		 * @var string
		 */
		private readonly string $discoverySection;
		
		/**
		 * Class responsible for validating providers are valid
		 * @var ProviderValidator
		 */
		private ProviderValidator $providerValidator;
		
		/**
		 * Logger instance for warnings
		 * @var LoggerInterface
		 */
		private LoggerInterface $logger;
		
		/**
		 * ComposerScanner constructor
		 * @param string|null $familyName The family name for providers
		 * @param string $discoverySection The top-level key in composer.json's extra section
		 * @param LoggerInterface|null $logger Logger instance for warnings
		 */
		public function __construct(
			string          $familyName = null,
			string          $discoverySection = self::DEFAULT_DISCOVERY_SECTION,
			LoggerInterface $logger = null
		) {
			$this->familyName = $familyName;
			$this->discoverySection = $discoverySection;
			$this->providerValidator = new ProviderValidator();
			$this->logger = $logger ?? new NullLogger();
		}
		
		/**
		 * Main entry point for provider discovery
		 * @return array<ProviderDefinition> Array of provider definitions
		 */
		public function scan(): array {
			// Fetch extra data sections from composer.json and composer.lock ("config/discovery-mapping.php")
			$composerInstalledLoader = new ComposerInstalledLoader();
			$composerJsonLoader = new ComposerJsonLoader();
			
			// Discover providers defined within the current project structure
			$discoveryMapping = array_merge($composerInstalledLoader->getData(), $composerJsonLoader->getData());
			
			// Discover providers from installed packages/dependencies
			// These are usually third-party providers from vendor/ directory
			$definitions = [];
			
			foreach ($discoveryMapping as $extraData) {
				// Check if package has opted into auto-discovery via 'extra.discover' section
				// This is the standard convention for packages that want their providers discovered
				if (isset($extraData[$this->discoverySection])) {
					// Extract and validate providers from this specific package
					// Uses the same validation logic as project providers
					$packageProviders = $this->extractAndValidateProviders($extraData[$this->discoverySection]);
					
					// Merge discovered providers into the main collection
					// Maintains order of discovery across packages
					$definitions = array_merge($definitions, $packageProviders);
				}
			}
			
			// Return all providers discovered from installed packages
			return $definitions;
		}
		
		/**
		 * This method performs a two-stage process: first extracting provider class
		 * definitions from composer configuration data, then validating each provider
		 * to ensure it's properly implemented and can be instantiated. Only valid
		 * providers are returned to prevent runtime errors during application bootstrap.
		 * @param array $discoverSection Complete composer.json data array
		 * @return array Array of validated provider data structures
		 */
		private function extractAndValidateProviders(array $discoverSection): array {
			// Extract raw provider class definitions and their configurations
			// from the composer config's discovery section (typically extra.discover)
			$providersWithConfig = $this->extractProviderClasses($discoverSection);
			
			// Validate each discovered provider class individually
			$validProviders = [];
			
			foreach ($providersWithConfig as $providerData) {
				// Perform comprehensive validation on the provider class:
				// - Check if class exists and can be autoloaded
				// - Verify it implements required ProviderInterface
				// - Ensure constructor is compatible with dependency injection
				if ($this->providerValidator->validate($providerData['class'])) {
					try {
						// Only include providers that pass all validation checks
						// This prevents runtime errors during provider instantiation
						$validProviders[] = $this->createProviderDefinition($providerData);
					} catch (InvalidArgumentException $e) {
						// Skip invalid provider definitions
						$this->logger->warning('Invalid provider definition for class: {class}', [
							'scanner' => 'ComposerScanner',
							'reason'  => 'invalid definition',
							'class'   => $providerData['class'],
							'error'   => $e->getMessage()
						]);
						continue;
					}
				} else {
					$this->logger->warning('Provider validation failed for class: {class}', [
						'scanner' => 'ComposerScanner',
						'reason'  => 'validation failed',
						'class'   => $providerData['class'],
						'family'  => $providerData['family']
					]);
				}
			}
			
			// Return only the providers that are confirmed to be valid and usable
			return $validProviders;
		}
		
		/**
		 * Create a ProviderDefinition from provider data
		 * @param array $providerData Raw provider data
		 * @return ProviderDefinition
		 */
		private function createProviderDefinition(array $providerData): ProviderDefinition {
			// Get class name
			$className = $providerData['class'];
			
			// Get metadata and defaults - interface guarantees these methods exist
			$metadata = $className::getMetadata();
			$defaults = $className::getDefaults();
			
			if (!isset($providerData['config'])) {
				$configFiles = [];
			} elseif (is_array($providerData['config'])) {
				$configFiles = $providerData['config'];
			} else {
				$configFiles = [$providerData['config']];
			}
			
			return new ProviderDefinition(
				className: $className,
				family: $providerData['family'],
				configFiles: $configFiles,
				metadata: $metadata,
				defaults: $defaults
			);
		}
		
		/**
		 * Parses the composer.json 'extra.discover' section to extract provider class
		 * definitions. Supports multiple configuration formats and can filter by provider
		 * family. This method handles the complexity of different discovery formats while
		 * maintaining backward compatibility.
		 * @param array $discoverSection The contents of the discovery section
		 * @return array Array of provider data structures
		 */
		protected function extractProviderClasses(array $discoverSection): array {
			// Process each provider family within the discovery section
			// Families group related providers (e.g., 'services', 'middleware', 'commands')
			$allProviders = [];
			
			foreach ($discoverSection as $familyName => $configSection) {
				// Apply family filtering if a specific family name has been configured
				// This allows selective discovery of only certain provider types
				if ($this->familyName !== null && $familyName !== $this->familyName) {
					continue;
				}
				
				// Skip malformed family configurations that aren't arrays
				// Each family section should contain provider definitions
				if (!is_array($configSection)) {
					continue;
				}
				
				// Handle array format: multiple providers listed in an array
				// Format: "family": ["Provider1", "Provider2", ...]
				$multipleProviders = $this->extractMultipleProviders($configSection, $familyName);
				
				// Handle object format: single provider with additional configuration
				// Format: "family": {"provider": "ProviderClass", "config": {...}}
				$singularProvider = $this->extractSingularProvider($configSection, $familyName);
				
				// Combine all providers found in this family into the main collection
				// Order is preserved: multiple providers first, then singular provider
				$allProviders = array_merge($allProviders, $multipleProviders, $singularProvider);
			}
			
			// Return all discovered providers from all processed families
			return $allProviders;
		}
		
		/**
		 * Extract providers from 'providers' array format
		 * @param array<string, mixed> $config Family configuration section containing providers array
		 * @param string $familyName Name of the provider family (e.g., 'services', 'middleware')
		 * @return array<array{class: string, config: ?string, family: string}> Array of normalized provider data structures with class, config, and family
		 */
		protected function extractMultipleProviders(array $config, string $familyName): array {
			// Extract the 'providers' array from the family configuration
			// This array contains multiple provider definitions in various formats
			$providersArray = $config['providers'] ?? [];
			
			// Validate that providers section is properly formatted as an array
			// Non-array values indicate configuration errors and should be ignored
			if (!is_array($providersArray)) {
				return [];
			}
			
			// Process each provider definition within the providers array
			$result = [];
			
			foreach ($providersArray as $definition) {
				// Handle simple string format: just the provider class name
				// Example: "App\Providers\RedisProvider"
				if (is_string($definition)) {
					// Normalize simple string definitions into standard structure
					$result[] = [
						'class'  => $definition,             // Fully qualified class name
						'config' => null,                   // No additional configuration
						'family' => $familyName             // Associate with current family
					];
					
					continue;
				}
				
				// Handle complex array format: provider with additional configuration
				// Example: {"class": "App\Providers\RedisProvider", "config": "redis.php"}
				if (is_array($definition) && isset($definition['class'])) {
					// Extract class name and optional configuration from array definition
					$result[] = [
						'class'  => $definition['class'],                    // Required: provider class
						'config' => $definition['config'] ?? null,         // Optional: config file/data
						'family' => $familyName                            // Associate with current family
					];
				}
			}
			
			// Return all successfully processed provider definitions
			return $result;
		}
		
		/**
		 * Extract provider from singular 'provider' format
		 * @param array $config Family configuration section that may contain a single provider
		 * @param string $familyName Name of the provider family for categorization
		 * @return array|array[] Array containing single provider data structure, or empty array
		 *                       if no valid provider found. Wrapped in array for consistency
		 *                       with extractMultipleProviders method
		 */
		protected function extractSingularProvider(array $config, string $familyName): array {
			// Check if this family configuration contains a singular provider definition
			// The 'provider' key (singular) is used for single provider configurations
			if (!isset($config['provider'])) {
				return [];
			}
			
			// Extract the provider definition and any separate configuration
			$definition = $config['provider'];
			
			// Check for configuration defined at the family level (separate from provider)
			// Format: {"provider": "Class", "config": "separate-config.php"}
			$separateConfig = $config['config'] ?? null;
			
			// Handle simple string format: provider defined as just the class name
			// Example: "provider" => "App\Providers\RedisProvider"
			if (is_string($definition)) {
				// Return normalized provider structure with separate config if available
				return [[
					'class'  => $definition,          // Provider class name
					'config' => $separateConfig,     // Use family-level config if present
					'family' => $familyName          // Associate with current family
				]];
			}
			
			// Handle complex array format: provider with inline configuration
			// Example: "provider" => {"class": "App\Providers\RedisProvider", "config": "redis.php"}
			if (is_array($definition) && isset($definition['class'])) {
				// Extract inline configuration from provider definition
				$inlineConfig = $definition['config'] ?? null;
				
				// Resolve configuration precedence: inline config overrides separate config
				// This allows for more specific configuration at the provider level
				$finalConfig = $inlineConfig ?? $separateConfig;
				
				return [[
					'class'  => $definition['class'],  // Required: provider class name
					'config' => $finalConfig,         // Resolved configuration with precedence
					'family' => $familyName           // Associate with current family
				]];
			}
			
			return [];
		}
	}