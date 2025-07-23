<?php
	
	namespace Quellabs\Discover\Scanner;
	
	use Psr\Log\LoggerInterface;
	use Quellabs\Discover\Utilities\ComposerPathResolver;
	use Quellabs\Discover\Utilities\ProviderValidator;
	use Quellabs\Contracts\Discovery\ProviderDefinition;
	
	/**
	 * Scans directories for classes that implement ProviderInterface
	 *
	 * This scanner recursively traverses directories to find PHP classes that:
	 * 1. Match an optional naming pattern (e.g., '/Provider$/' for classes ending with "Provider")
	 * 2. Implement the ProviderInterface
	 */
	class DirectoryScanner implements ScannerInterface {
		
		/**
		 * Constants
		 */
		private const string DEFAULT_FAMILY_NAME = 'default';
		
		/**
		 * Class used for logging
		 * @var LoggerInterface|null
		 */
		private ?LoggerInterface $logger;
		
		/**
		 * Directories to scan
		 * @var array<string>
		 */
		protected array $directories = [];
		
		/**
		 * Optional regular expression pattern to filter class names
		 *
		 * Examples:
		 * - '/Provider$/' - Only classes ending with "Provider"
		 * - '/^App\\\\Service\\\\/' - Only classes in the App\Service namespace
		 * - null - No filtering, all classes implementing ProviderInterface are included
		 *
		 * @var string|null
		 */
		protected ?string $pattern;
		
		/**
		 * Default family name for discovered providers
		 * @var string
		 */
		protected string $defaultFamily;
		
		/**
		 * Cache of already scanned classes
		 * @var array<string, bool>
		 */
		protected array $scannedClasses = [];
		
		/**
		 * @var ComposerPathResolver PSR-4 utilities
		 */
		protected ComposerPathResolver $utilities;
		
		/**
		 * Class responsible for validating providers are valid
		 * @var ProviderValidator
		 */
		protected ProviderValidator $providerValidator;
		
		/**
		 * DirectoryScanner constructor
		 * @param array<string> $directories Directories to scan
		 * @param string|null $pattern Regex pattern for class names (e.g., '/Provider$/')
		 * @param string $defaultFamily Default family name for discovered providers
		 */
		public function __construct(
			array $directories = [],
			?string $pattern = null,
			string $defaultFamily = self::DEFAULT_FAMILY_NAME,
			?LoggerInterface $logger = null
		) {
			$this->directories = $directories;
			$this->pattern = $pattern;
			$this->defaultFamily = $defaultFamily;
			$this->utilities = new ComposerPathResolver();
			$this->logger = $logger;
			$this->providerValidator = new ProviderValidator();
		}
		
		/**
		 * Scan directories for classes that implement ProviderInterface
		 * @return array<ProviderDefinition> Array of provider definitions
		 */
		public function scan(): array {
			// Get the configured directories to scan
			$dirs = $this->directories;
			
			// Scan each directory and merge the results
			$providerData = [];

			foreach ($dirs as $directory) {
				// Scan individual directory and combine results with existing discoveries
				// Each scanDirectory call returns an array of ProviderDefinition objects
				$providerData = array_merge($providerData, $this->scanDirectory($directory));
			}
			
			// Log the summary of the scan
			$this->logger?->info('Directory scanning completed', [
				'total_providers'     => count($providerData),
				'directories_scanned' => count($dirs)
			]);
			
			// Return all discovered provider definitions across all directories
			return $providerData;
		}
		
		/**
		 * This function traverses a directory structure, identifies all PHP files,
		 * attempts to extract class names from them, and checks if each class implements
		 * the ProviderInterface. All valid provider class data is returned.
		 * @param string $directory The root directory path to begin scanning
		 * @return array Array of provider data with class and family information
		 */
		protected function scanDirectory(string $directory): array {
			// Verify the directory exists and is accessible before attempting to scan
			if (!is_dir($directory) || !is_readable($directory)) {
				$this->logger?->warning('Cannot scan directory', [
					'scanner'   => 'DirectoryScanner',
					'reason'    => 'directory_not_readable',
					'directory' => $directory,
					'exists'    => is_dir($directory),
					'readable'  => is_readable($directory)
				]);

				return [];
			}
			
			// Fetch all provider classes found in the directory
			// Filter out the class names we don't want (filter)
			$classes = $this->utilities->findClassesInDirectory($directory, function($className) {
				// Check class validity
				if (!$this->providerValidator->validate($className)) {
					return false;
				}
				
				// If a naming pattern was specified, check if the class name matches
				// This allows filtering for specific naming conventions (e.g., all classes ending with "Provider")
				return $this->pattern === null || preg_match($this->pattern, $className);
			});
			
			// Process each valid class found in the directory structure
			$definitions = [];
			
			foreach ($classes as $className) {
				try {
					$definitions[] = new ProviderDefinition(
						className: $className,
						family: $this->defaultFamily,
						configFiles: [],
						metadata: $className::getMetadata(),
						defaults: $className::getDefaults()
					);
				} catch (\Throwable $e) {
					$this->logger?->warning('Failed to create provider definition', [
						'scanner' => 'DirectoryScanner',
						'class'   => $className,
						'error'   => $e->getMessage()
					]);
				}
			}
			
			// Return all discovered provider class data from the directory
			return $definitions;
		}
	}