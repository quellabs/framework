<?php
	
	namespace Quellabs\Discover\Scanner;
	
	use Psr\Log\LoggerInterface;
	use Psr\Log\NullLogger;
	use Quellabs\Discover\Utilities\ProviderValidator;
	use Quellabs\Contracts\Discovery\ProviderDefinition;
	use Quellabs\Support\ComposerUtils;
	use RuntimeException;
	use InvalidArgumentException;
	
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
		 * @var LoggerInterface
		 */
		private LoggerInterface $logger;
		
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
		 * Track discovered provider classes to prevent duplicates
		 * @var array<string, bool>
		 */
		private array $discoveredClasses = [];
		
		/**
		 * Whether to throw exceptions on validation failures instead of logging warnings
		 * @var bool
		 */
		private bool $strictMode;
		
		/**
		 * Constructor
		 * @param array<string> $directories Directories to scan
		 * @param string|null $pattern Regex pattern for class names (e.g. '/Provider$/')
		 * @param string $defaultFamily Default family name for discovered providers
		 * @param LoggerInterface|null $logger Logger instance for warnings
		 * @param bool $strictMode Whether to throw exceptions on validation failures
		 * @throws InvalidArgumentException If pattern is invalid regex
		 */
		public function __construct(
			array            $directories = [],
			?string          $pattern = null,
			string           $defaultFamily = self::DEFAULT_FAMILY_NAME,
			?LoggerInterface $logger = null,
			bool             $strictMode = false
		) {
			$this->directories = $this->normalizeDirectories($directories);
			$this->pattern = $this->validatePattern($pattern);
			$this->defaultFamily = $defaultFamily;
			$this->logger = $logger ?? new NullLogger();
			$this->strictMode = $strictMode;
			$this->providerValidator = new ProviderValidator();
		}
		
		/**
		 * Scan all configured directories for classes that implement ProviderInterface.
		 * @return array<ProviderDefinition> List of provider definitions
		 */
		public function scan(): array {
			// Reset discovered classes tracker for fresh scan
			$this->discoveredClasses = [];
			
			$providerData = [];
			$stats = [
				'total_classes_found' => 0,
				'pattern_filtered'    => 0,
				'validation_failed'   => 0,
				'duplicates_skipped'  => 0,
				'providers_created'   => 0
			];
			
			foreach ($this->directories as $directory) {
				// Each scanDirectory call returns an array of ProviderDefinition objects
				$result = $this->scanDirectory($directory);
				$providerData = array_merge($providerData, $result['definitions']);
				
				// Aggregate statistics
				$stats['total_classes_found'] += $result['stats']['total_classes_found'];
				$stats['pattern_filtered'] += $result['stats']['pattern_filtered'];
				$stats['validation_failed'] += $result['stats']['validation_failed'];
				$stats['duplicates_skipped'] += $result['stats']['duplicates_skipped'];
				$stats['providers_created'] += $result['stats']['providers_created'];
			}
			
			// Log a summary of the scan
			$this->logger->info('Directory scanning completed', [
				'scanner'             => 'DirectoryScanner',
				'total_providers'     => count($providerData),
				'directories_scanned' => count($this->directories),
				'statistics'          => $stats
			]);
			
			return $providerData;
		}
		
		/**
		 * Traverses a directory, finds PHP files, extracts class names, and checks
		 * whether they implement ProviderInterface. All valid provider classes are returned.
		 * @param string $directory Root directory to scan
		 * @return array{definitions: array<ProviderDefinition>, stats: array} Provider definitions and statistics
		 */
		protected function scanDirectory(string $directory): array {
			$stats = [
				'total_classes_found' => 0,
				'pattern_filtered'    => 0,
				'validation_failed'   => 0,
				'duplicates_skipped'  => 0,
				'providers_created'   => 0
			];
			
			// Ensure the directory exists and is readable
			if (!is_dir($directory) || !is_readable($directory)) {
				$this->handleError(
					'Cannot scan directory: {directory}',
					[
						'directory' => $directory,
						'exists'    => is_dir($directory),
						'readable'  => is_readable($directory)
					]
				);
				
				return ['definitions' => [], 'stats' => $stats];
			}
			
			// Retrieve all classes in directory (no filtering yet)
			$allClasses = ComposerUtils::findClassesInDirectory($directory);
			$stats['total_classes_found'] = count($allClasses);
			
			// Filter and validate classes
			$definitions = [];
			
			foreach ($allClasses as $className) {
				// Check pattern first (cheaper than validation)
				if ($this->pattern !== null && !preg_match($this->pattern, $className)) {
					$stats['pattern_filtered']++;
					continue;
				}
				
				// Check for duplicates
				if (isset($this->discoveredClasses[$className])) {
					$stats['duplicates_skipped']++;
					$this->logger->debug('Duplicate provider class skipped: {class}', [
						'scanner' => 'DirectoryScanner',
						'class'   => $className
					]);
					continue;
				}
				
				// Validate provider
				if (!$this->providerValidator->validate($className)) {
					$stats['validation_failed']++;
					continue;
				}
				
				// Mark as discovered
				$this->discoveredClasses[$className] = true;
				
				// Create provider definition
				try {
					$definitions[] = $this->createProviderDefinition($className);
					$stats['providers_created']++;
				} catch (\Throwable $e) {
					$this->handleError(
						'Failed to create provider definition for class: {class}',
						[
							'class' => $className,
							'error' => $e->getMessage()
						],
						$e
					);
				}
			}
			
			return ['definitions' => $definitions, 'stats' => $stats];
		}
		
		/**
		 * Create a ProviderDefinition from a class name
		 * @param string $className Fully qualified class name
		 * @return ProviderDefinition
		 * @throws \Throwable If provider definition cannot be created
		 */
		private function createProviderDefinition(string $className): ProviderDefinition {
			return new ProviderDefinition(
				className: $className,
				family: $this->defaultFamily,
				configFiles: [],
				metadata: $className::getMetadata(),
				defaults: $className::getDefaults()
			);
		}
		
		/**
		 * Normalize and validate directories
		 * @param array<string> $directories Raw directory paths
		 * @return array<string> Normalized absolute paths
		 */
		private function normalizeDirectories(array $directories): array {
			$normalized = [];
			
			foreach ($directories as $directory) {
				// Convert to absolute path
				$realPath = realpath($directory);
				
				if ($realPath === false) {
					$this->logger->warning('Directory does not exist, will be skipped during scan: {directory}', [
						'scanner'   => 'DirectoryScanner',
						'directory' => $directory
					]);
					continue;
				}
				
				$normalized[] = $realPath;
			}
			
			return $normalized;
		}
		
		/**
		 * Validate regex pattern
		 * @param string|null $pattern Regex pattern or null
		 * @return string|null Validated pattern
		 * @throws InvalidArgumentException If pattern is invalid regex
		 */
		private function validatePattern(?string $pattern): ?string {
			if ($pattern === null) {
				return null;
			}
			
			// Test the pattern with preg_match to catch syntax errors
			// The @ suppresses the warning, we check the return value instead
			$result = @preg_match($pattern, '');
			
			if ($result === false) {
				throw new InvalidArgumentException(
					"Invalid regex pattern: {$pattern}. Error: " . preg_last_error_msg()
				);
			}
			
			return $pattern;
		}
		
		/**
		 * Handle errors consistently - log in normal mode, throw in strict mode
		 * @param string $message Error message with placeholders
		 * @param array<string, mixed> $context Context data for logging
		 * @param \Throwable|null $previous Previous exception if any
		 * @return void
		 * @throws RuntimeException In strict mode
		 */
		private function handleError(string $message, array $context = [], ?\Throwable $previous = null): void {
			// Set scanner
			$context['scanner'] = 'DirectoryScanner';
			
			// In strict mode, throw exception
			if ($this->strictMode) {
				throw new RuntimeException($this->formatLogMessage($message, $context), 0, $previous);
			}
			
			// In normal mode, log warning
			$this->logger->warning($message, $context);
		}
		
		/**
		 * Format log message by replacing placeholders with context values
		 * @param string $message Message with {placeholder} syntax
		 * @param array<string, mixed> $context Context data
		 * @return string Formatted message
		 */
		private function formatLogMessage(string $message, array $context): string {
			$replace = [];
			
			foreach ($context as $key => $val) {
				$replace['{' . $key . '}'] = is_scalar($val) ? (string)$val : json_encode($val);
			}
			
			return strtr($message, $replace);
		}
	}