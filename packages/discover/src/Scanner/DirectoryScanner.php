<?php
	
	namespace Quellabs\Discover\Scanner;
	
	use Psr\Log\LoggerInterface;
	use Psr\Log\NullLogger;
	use Quellabs\Contracts\Discovery\ProviderInterface;
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
		 * Tracks fully qualified class names already yielded during the current scan()
		 * call to prevent the same class from being registered twice when multiple
		 * configured directories overlap or contain symlinks to the same files.
		 * Reset at the start of every scan() call so the scanner is reusable.
		 * @var array<string, bool>
		 */
		private array $discoveredClasses = [];
		
		/**
		 * Whether to throw exceptions on validation failures instead of logging warnings
		 * @var bool
		 */
		private bool $strictMode;
		
		/**
		 * Constructor.
		 * Logger is assigned first so normalizeDirectories() can use it for warnings
		 * about non-existent paths without a dependency ordering issue.
		 * @param array<string> $directories Directories to scan
		 * @param string|null $pattern Regex pattern for class names (e.g. '/Provider$/')
		 * @param string $defaultFamily Default family name for discovered providers
		 * @param LoggerInterface $logger Logger instance for warnings
		 * @param bool $strictMode Whether to throw exceptions on validation failures
		 * @throws InvalidArgumentException If pattern is invalid regex
		 */
		public function __construct(
			array            $directories = [],
			?string          $pattern = null,
			string           $defaultFamily = self::DEFAULT_FAMILY_NAME,
			LoggerInterface  $logger = new NullLogger(),
			bool             $strictMode = false
		) {
			// Logger must be assigned before normalizeDirectories() is called,
			// because that method may emit warnings about non-existent paths.
			$this->logger = $logger;
			$this->directories = $this->normalizeDirectories($directories);
			$this->pattern = $pattern;
			$this->defaultFamily = $defaultFamily;
			$this->strictMode = $strictMode;
			$this->providerValidator = new ProviderValidator();
			
			$this->validatePattern($pattern);
		}
		
		/**
		 * Scan all configured directories for classes that implement ProviderInterface.
		 * @return array<ProviderDefinition> List of provider definitions
		 */
		public function scan(): array {
			// Reset the deduplication tracker so repeated scan() calls on the same
			// instance do not carry over state from a previous run.
			$this->discoveredClasses = [];
			
			$providerData = [];
			
			foreach ($this->directories as $directory) {
				// Append definitions without reallocating the entire array on every
				// iteration, which array_merge() would do.
				array_push($providerData, ...$this->scanDirectory($directory));
			}
			
			return $providerData;
		}
		
		/**
		 * Traverses a single directory, finds PHP files, extracts class names, and checks
		 * whether they implement ProviderInterface. All valid provider classes are returned.
		 * @param string $directory Root directory to scan
		 * @return array<ProviderDefinition> Provider definitions found in this directory
		 */
		protected function scanDirectory(string $directory): array {
			// Capture is_dir/is_readable results once to avoid calling the same
			// filesystem stat twice — once for the guard and once for the error context.
			$exists   = is_dir($directory);
			$readable = $exists && is_readable($directory);
			
			if (!$exists || !$readable) {
				$this->handleError(
					'Cannot scan directory: {directory}',
					[
						'directory' => $directory,
						'exists'    => $exists,
						'readable'  => $readable,
					]
				);
				
				return [];
			}
			
			// Ask ComposerUtils to map every PHP file in the directory tree to its
			// fully qualified class name using the Composer autoload map.
			$allClasses  = ComposerUtils::findClassesInDirectory($directory);
			$definitions = [];
			
			foreach ($allClasses as $className) {
				// Pattern check comes first — it only costs a regex match and lets us
				// skip the more expensive class_exists/reflection calls in the validator.
				if ($this->pattern !== null && !preg_match($this->pattern, $className)) {
					continue;
				}
				
				// Deduplication check: the same class can appear when multiple configured
				// directories overlap (e.g. a parent and a subdirectory are both listed).
				if (isset($this->discoveredClasses[$className])) {
					$this->logger->debug('Duplicate provider class skipped: {class}', [
						'scanner' => 'DirectoryScanner',
						'class'   => $className,
					]);
					continue;
				}
				
				// Full validation: checks class name format, autoloadability, interface
				// implementation, and instantiability. Failing any of these is not an
				// error worth surfacing — the directory may legitimately contain helper
				// classes that are not providers.
				if (!$this->providerValidator->validate($className)) {
					continue;
				}
				
				// Mark as discovered before attempting definition creation so that a
				// Throwable during creation does not leave the class unmarked and cause
				// it to be retried (and fail again) on the next directory.
				$this->discoveredClasses[$className] = true;
				
				// createProviderDefinition() calls static methods on the class. Wrap in
				// try/catch so a misbehaving provider does not abort the entire scan.
				try {
					$definitions[] = $this->createProviderDefinition($className);
				} catch (\Throwable $e) {
					$this->handleError(
						'Failed to create provider definition for class: {class}',
						[
							'class' => $className,
							'error' => $e->getMessage(),
						],
						$e
					);
				}
			}
			
			return $definitions;
		}
		
		/**
		 * Builds a ProviderDefinition for a class that has already passed validation.
		 * Interface compliance is guaranteed by ProviderValidator, so no redundant
		 * is_subclass_of check is needed here.
		 * @param class-string<ProviderInterface> $className Fully qualified class name
		 * @return ProviderDefinition
		 * @throws \Throwable If getMetadata() or getDefaults() throw
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
		 * Resolves each raw directory path to a canonical absolute path via realpath().
		 * Paths that do not exist are logged and skipped rather than stored, so scan()
		 * never has to deal with paths it cannot open.
		 * @param array<string> $directories Raw directory paths
		 * @return array<string> Normalized absolute paths
		 */
		private function normalizeDirectories(array $directories): array {
			$normalized = [];
			
			foreach ($directories as $directory) {
				// realpath() resolves symlinks and normalizes separators.
				// It returns false when the path does not exist.
				$realPath = realpath($directory);
				
				if ($realPath === false) {
					$this->logger->warning('Directory does not exist, will be skipped during scan: {directory}', [
						'scanner'   => 'DirectoryScanner',
						'directory' => $directory,
					]);
					
					continue;
				}
				
				$normalized[] = $realPath;
			}
			
			return $normalized;
		}
		
		/**
		 * Validates that the supplied pattern is syntactically correct before storing it.
		 * Catching a broken pattern here produces a clear error at construction time
		 * rather than a cryptic preg_match warning on the first scan.
		 * @param string|null $pattern Regex pattern or null
		 * @throws InvalidArgumentException If pattern is invalid regex
		 */
		private function validatePattern(?string $pattern): void {
			// No pattern configured, nothing to validate.
			if ($pattern === null) {
				return;
			}
			
			// Run a test match against an empty string to catch syntax errors.
			// @ suppresses the PHP warning; the false return value is what we act on.
			if (@preg_match($pattern, '') === false) {
				throw new InvalidArgumentException(
					"Invalid regex pattern: {$pattern}. Error: " . preg_last_error_msg()
				);
			}
		}
		
		/**
		 * Central error handler that respects strict mode.
		 * In normal mode errors are logged as warnings so a single bad provider or
		 * unreadable directory does not abort the entire discovery process.
		 * In strict mode every error becomes a RuntimeException, which is useful
		 * during development or in environments where misconfiguration must be loud.
		 * @param string $message Error message with {placeholder} syntax
		 * @param array<string, mixed> $context Context data for logging
		 * @param \Throwable|null $previous Previous exception if any
		 * @return void
		 * @throws RuntimeException In strict mode
		 */
		private function handleError(string $message, array $context = [], ?\Throwable $previous = null): void {
			// Always inject the scanner name so log entries and exception messages
			// are identifiable when multiple scanners are active simultaneously.
			$context['scanner'] = 'DirectoryScanner';
			
			// In strict mode, every error is fatal — useful during development or in
			// environments where silent failures are unacceptable.
			if ($this->strictMode) {
				throw new RuntimeException($this->formatLogMessage($message, $context), 0, $previous);
			}
			
			// In normal mode, degrade gracefully by logging a warning and letting the
			// scan continue with whatever providers were successfully discovered.
			$this->logger->warning($message, $context);
		}
		
		/**
		 * Interpolates PSR-3 style {placeholder} tokens in a message string using the
		 * supplied context array. Used only when building exception messages in strict
		 * mode, since PSR-3 loggers handle interpolation themselves.
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