<?php
	
	namespace Quellabs\Discover\Scanner;
	
	use Psr\Log\LoggerInterface;
	use Psr\Log\NullLogger;
	use Quellabs\Discover\Utilities\ComposerInstalledLoader;
	use Quellabs\Discover\Utilities\ComposerJsonLoader;
	
	/**
	 * Collects all string-array metadata from a named family within the
	 * 'extra.discover' section of installed packages' composer.json files.
	 *
	 * Each package can declare any number of keys under the family. This collector
	 * gathers them all, merges values by key across packages, and resolves any
	 * relative paths to absolute paths.
	 *
	 * Example composer.json entry in a package:
	 *
	 *   "extra": {
	 *       "discover": {
	 *           "canvas": {
	 *               "controllers": ["src/Controllers", "src/Api"],
	 *               "middleware":  ["src/Middleware"]
	 *           }
	 *       }
	 *   }
	 *
	 * Usage:
	 *
	 *   $collector = new MetadataCollector('canvas');
	 *   $result = $collector->collect();
	 *   // ['controllers' => ['/abs/path/src/Controllers', ...], 'middleware' => [...]]
	 */
	class MetadataCollector implements MetadataScannerInterface {
		
		/**
		 * The top-level key in composer.json's extra section that contains discovery config.
		 * @var string
		 */
		private readonly string $discoverySection;
		
		/**
		 * The family name within the discovery section to read from (e.g. 'canvas').
		 * @var string
		 */
		private readonly string $familyName;
		
		/**
		 * Logger for non-fatal issues (malformed entries, missing paths, etc.)
		 * @var LoggerInterface
		 */
		private LoggerInterface $logger;
		
		/**
		 * Whether to throw exceptions on invalid entries instead of logging warnings.
		 * @var bool
		 */
		private bool $strictMode;
		
		/**
		 * @param string $familyName The family to read from within the discovery section (e.g. 'canvas')
		 * @param string $discoverySection The top-level key in extra (default: 'discover')
		 * @param LoggerInterface|null $logger
		 * @param bool $strictMode
		 */
		public function __construct(
			string           $familyName,
			string           $discoverySection = ComposerScanner::DEFAULT_DISCOVERY_SECTION,
			?LoggerInterface $logger = null,
			bool             $strictMode = false
		) {
			$this->familyName = $familyName;
			$this->discoverySection = $discoverySection;
			$this->logger = $logger ?? new NullLogger();
			$this->strictMode = $strictMode;
		}
		
		/**
		 * @inheritDoc
		 */
		public function getFamilyName(): string {
			return $this->familyName;
		}
		
		/**
		 * Collect all key/value metadata for this family across all packages.
		 *
		 * Iterates every installed package, finds the family section, and returns
		 * results grouped by package name to preserve the relationship between keys
		 * declared together in the same composer.json.
		 *
		 * @return array<string, array<string, string[]>> e.g. ['vendor/pkg' => ['controllers' => [...], ...]]
		 */
		public function collect(): array {
			$installedLoader = new ComposerInstalledLoader();
			$jsonLoader = new ComposerJsonLoader();
			
			$allPackageData = array_merge(
				$installedLoader->getData(),
				$jsonLoader->getData()
			);
			
			// Accumulator: ['vendor/pkg' => ['controllers' => ['/path' => true, ...], ...]]
			// Using path-as-key internally for O(1) deduplication, converted at the end
			$collected = [];
			
			foreach ($allPackageData as $packageName => $packageData) {
				// Skip packages that don't participate in discovery at all
				if (!isset($packageData[$this->discoverySection]) || !is_array($packageData[$this->discoverySection])) {
					continue;
				}
				
				// Skip packages that don't have this family
				$discoverySection = $packageData[$this->discoverySection];
				
				if (!isset($discoverySection[$this->familyName])) {
					continue;
				}
				
				$familyConfig = $discoverySection[$this->familyName];
				
				if (!is_array($familyConfig)) {
					$this->handleError(
						'Family "{family}" must be an array (package: {package})',
						['family' => $this->familyName, 'package' => $packageName]
					);
					continue;
				}
				
				$installPath = $this->resolveInstallPath($packageData);
				
				// Iterate every key declared under the family (e.g. 'controllers', 'middleware')
				foreach ($familyConfig as $metadataKey => $rawValues) {
					// Normalise: accept both a bare string and an array of strings
					if (is_string($rawValues)) {
						$rawValues = [$rawValues];
					}
					
					if (!is_array($rawValues)) {
						$this->handleError(
							'Key "{key}" must be a string or array of strings in family "{family}" (package: {package})',
							['key' => $metadataKey, 'family' => $this->familyName, 'package' => $packageName]
						);
						continue;
					}
					
					foreach ($rawValues as $value) {
						if (!is_string($value)) {
							$this->handleError(
								'Non-string value found under "{key}" in family "{family}" (package: {package})',
								['key' => $metadataKey, 'family' => $this->familyName, 'package' => $packageName]
							);
							continue;
						}
						
						$absolutePath = $this->toAbsolutePath($value, $installPath);
						
						if (!file_exists($absolutePath)) {
							$this->handleError(
								'Declared path does not exist: {path} (package: {package})',
								['path' => $absolutePath, 'package' => $packageName]
							);
							continue;
						}
						
						// Store under package name to preserve per-package grouping
						$collected[$packageName][$metadataKey][$absolutePath] = true;
					}
				}
			}
			
			// Convert inner deduplication maps to plain indexed arrays
			return array_map(fn($keys) => array_map('array_keys', $keys), $collected);
		}
		
		/**
		 * Resolve the filesystem install path for a package.
		 * ComposerInstalledLoader includes 'install-path'; local project falls back to project root.
		 * @param array $packageData Raw package data from the loader
		 * @return string Absolute directory path (no trailing separator)
		 */
		private function resolveInstallPath(array $packageData): string {
			if (!empty($packageData['install-path']) && is_string($packageData['install-path'])) {
				return rtrim($packageData['install-path'], '/\\');
			}
			
			return rtrim(\Quellabs\Support\ComposerUtils::getProjectRoot(), '/\\');
		}
		
		/**
		 * Convert a potentially relative path to an absolute path.
		 * @param string $path The path as declared in composer.json
		 * @param string $basePath Absolute base directory to resolve relative paths against
		 * @return string
		 */
		private function toAbsolutePath(string $path, string $basePath): string {
			if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path)) {
				return $path;
			}
			
			return $basePath . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
		}
		
		/**
		 * Handle errors consistently — log in normal mode, throw in strict mode.
		 * @param string $message Message template with {placeholder} syntax
		 * @param array $context Placeholder values and additional log context
		 * @param \Throwable|null $previous Chained exception, if any
		 * @throws \RuntimeException In strict mode
		 */
		private function handleError(string $message, array $context = [], ?\Throwable $previous = null): void {
			$context['scanner'] = 'MetadataCollector';
			
			if ($this->strictMode) {
				throw new \RuntimeException($this->formatMessage($message, $context), 0, $previous);
			}
			
			$this->logger->warning($message, $context);
		}
		
		/**
		 * Interpolate {placeholder} tokens in a message string.
		 * @param string $message
		 * @param array $context
		 * @return string
		 */
		private function formatMessage(string $message, array $context): string {
			$replacements = [];
			
			foreach ($context as $key => $value) {
				$replacements['{' . $key . '}'] = is_scalar($value) ? (string)$value : json_encode($value);
			}
			
			return strtr($message, $replacements);
		}
	}