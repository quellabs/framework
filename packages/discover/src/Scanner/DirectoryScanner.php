<?php
	
	namespace Quellabs\Discover\Scanner;
	
	use Psr\Log\LoggerInterface;
	use Quellabs\Discover\Utilities\ProviderValidator;
	use Quellabs\Contracts\Discovery\ProviderDefinition;
	use Quellabs\Support\ComposerUtils;
	
	/**
	 * Scans directories for classes that implement ProviderInterface.
	 *
	 * This scanner recursively traverses directories to find PHP classes that:
	 * 1. Optionally match a naming pattern (e.g. '/Provider$/' for classes ending with "Provider")
	 * 2. Implement ProviderInterface
	 */
	class DirectoryScanner implements ScannerInterface {
		
		/**
		 * Constants
		 */
		private const string DEFAULT_FAMILY_NAME = 'default';
		
		/**
		 * Logger instance.
		 * @var LoggerInterface|null
		 */
		private ?LoggerInterface $logger;
		
		/**
		 * Directories to scan.
		 * @var array<string>
		 */
		protected array $directories = [];
		
		/**
		 * Optional regular expression used to filter class names.
		 *
		 * Examples:
		 * - '/Provider$/' — only classes ending with "Provider"
		 * - '/^App\\\\Service\\\\/' — only classes in the App\Service namespace
		 * - null — no filtering; all classes implementing ProviderInterface are included
		 *
		 * @var string|null
		 */
		protected ?string $pattern;
		
		/**
		 * Default family name for discovered providers.
		 * @var string
		 */
		protected string $defaultFamily;
		
		/**
		 * Validates that providers are valid.
		 * @var ProviderValidator
		 */
		protected ProviderValidator $providerValidator;
		
		/**
		 * Constructor
		 * @param array<string> $directories Directories to scan
		 * @param string|null $pattern Regex pattern for class names (e.g. '/Provider$/')
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
			$this->logger = $logger;
			$this->providerValidator = new ProviderValidator();
		}
		
		/**
		 * Scan all configured directories for classes that implement ProviderInterface.
		 * @return array<ProviderDefinition> List of provider definitions
		 */
		public function scan(): array {
			$providerData = [];
			
			foreach ($this->directories as $directory) {
				// Each scanDirectory call returns an array of ProviderDefinition objects
				$providerData = array_merge($providerData, $this->scanDirectory($directory));
			}
			
			// Log a summary of the scan
			$this->logger?->info('Directory scanning completed', [
				'total_providers'     => count($providerData),
				'directories_scanned' => count($this->directories)
			]);
			
			return $providerData;
		}
		
		/**
		 * Traverses a directory, finds PHP files, extracts class names, and checks
		 * whether they implement ProviderInterface. All valid provider classes are returned.
		 * @param string $directory Root directory to scan
		 * @return array<ProviderDefinition> Provider definitions found in this directory
		 */
		protected function scanDirectory(string $directory): array {
			// Ensure the directory exists and is readable
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
			
			// Retrieve provider classes, filtering by pattern and validator
			$classes = ComposerUtils::findClassesInDirectory($directory, function($className) {
				if (!$this->providerValidator->validate($className)) {
					return false;
				}
				
				// Apply naming pattern if provided
				return $this->pattern === null || preg_match($this->pattern, $className);
			});
			
			// Fetch definitions
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
			
			return $definitions;
		}
	}