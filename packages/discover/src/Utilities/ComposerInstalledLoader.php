<?php
	
	namespace Quellabs\Discover\Utilities;
	
	use Psr\Log\NullLogger;
	use Psr\Log\LoggerInterface;
	use Quellabs\Support\ComposerUtils;
	
	/**
	 * Handles loading and parsing Composer's installed packages data
	 * Supports both modern PHP format (installed.php) and legacy JSON format (installed.json)
	 * Uses PSR4 utility class for file path resolution
	 */
	class ComposerInstalledLoader {
		
		/**
		 * @var array<string, array|null> Cache of parsed installed files
		 */
		private array $installedDataCache = [];
		
		/**
		 * Directory to start searching from (defaults to current directory)
		 * @var string|null
		 */
		private ?string $startDirectory;
		
		/**
		 * @var LoggerInterface
		 */
		private LoggerInterface $logger;
		
		/**
		 * Constructor
		 * @param string|null $startDirectory Directory to start searching from (defaults to current directory)
		 * @param LoggerInterface|null $logger Logger instance (uses NullLogger if not provided)
		 */
		public function __construct(
			?string               $startDirectory = null,
			?LoggerInterface      $logger = null
		) {
			$this->startDirectory = $startDirectory;
			$this->logger = $logger ?? new NullLogger();
		}
		
		/**
		 * Parse and return installed packages data with caching
		 * Automatically handles both PHP and JSON formats, preferring PHP
		 * @return array|null Parsed installed packages data or null on failure
		 */
		public function getData(): ?array {
			// Try the cache file first (generated by ServiceDiscoveryPlugin)
			$mappingPath = ComposerUtils::getDiscoveryMappingPath($this->startDirectory);
			
			if ($mappingPath !== null) {
				// Found a mapping file, pull it in
				try {
					return $this->installedDataCache[$mappingPath] = include $mappingPath;
				} catch (\Throwable $e) {
					$this->logger->warning('Failed to include discovery mapping file', [
						'scanner'       => 'ComposerScanner',
						'reason'        => 'Exception occurred while including discovery mapping file',
						'file_path'     => $mappingPath,
						'error_message' => $e->getMessage(),
						'error_type'    => get_class($e)
					]);
				}
			} else {
				$this->logger->warning('Discovery mapping file not found', [
					'scanner'         => 'ComposerScanner',
					'reason'          => 'No discovery mapping file found, falling back to JSON format',
					'start_directory' => $this->startDirectory ?? getcwd()
				]);
			}
			
			// Fallback to JSON format (legacy Composer)
			// JSON format is used by older Composer versions and when lockfile is unavailable
			$jsonPath = ComposerUtils::getComposerInstalledJsonPath($this->startDirectory);
			
			if ($jsonPath !== null) {
				// Found installed.json file, attempt to load and parse it
				return $this->installedDataCache[$jsonPath] = $this->parseJsonFile($jsonPath);
			}
			
			// No installed packages file found in either format
			$this->logger->warning('No Composer installed files found', [
				'scanner'         => 'ComposerScanner',
				'reason'          => 'Neither discovery mapping nor installed.json files could be located',
				'start_directory' => $this->startDirectory ?? getcwd(),
				'project_root'    => ComposerUtils::getProjectRoot($this->startDirectory)
			]);
			
			return null;
		}
		
		/**
		 * Parse a JSON file and return package extra data as an array
		 * @param string $filePath Path to the JSON file
		 * @return array Parsed extra data as "package-name" => extra_block
		 */
		protected function parseJsonFile(string $filePath): array {
			// Check if the file exists and is readable
			if (!is_readable($filePath)) {
				$this->logger->warning('JSON file not readable', [
					'scanner'          => 'ComposerScanner',
					'reason'           => 'File exists but is not readable (permission issue)',
					'file_path'        => $filePath,
					'file_exists'      => file_exists($filePath),
					'file_permissions' => file_exists($filePath) ? decoct(fileperms($filePath) & 0777) : 'N/A'
				]);
				return [];
			}
			
			// Read the entire file contents into a string
			$content = file_get_contents($filePath);
			
			// Check if file reading was successful
			if ($content === false) {
				$this->logger->warning('Failed to read JSON file contents', [
					'scanner'   => 'ComposerScanner',
					'reason'    => 'file_get_contents() returned false',
					'file_path' => $filePath,
					'file_size' => file_exists($filePath) ? filesize($filePath) : 'N/A'
				]);
				return [];
			}
			
			// Decode the JSON string into a PHP array
			// The second parameter 'true' ensures we get an associative array instead of objects
			$data = json_decode($content, true);
			
			// Check if JSON parsing was successful by examining the last JSON error
			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->logger->warning('JSON parsing failed', [
					'scanner'         => 'ComposerScanner',
					'reason'          => 'Invalid JSON syntax in file',
					'file_path'       => $filePath,
					'json_error'      => json_last_error_msg(),
					'json_error_code' => json_last_error()
				]);
				
				return [];
			}
			
			// Extract the packages array
			if (isset($data['packages'])) {
				$packages = $data['packages'];
			} elseif (is_array($data) && $this->isPackageArray($data)) {
				$packages = $data;
			} else {
				return [];
			}
			
			// Extract extra blocks from packages
			$extraMap = [];
			$packagesWithoutName = 0;
			
			foreach ($packages as $package) {
				if (!isset($package['name'])) {
					$packagesWithoutName++;
				} elseif (!empty($package['extra'])) {
					$extraMap[$package['name']] = $package['extra'];
				}
			}
			
			if ($packagesWithoutName > 0) {
				$this->logger->warning('Packages missing name field', [
					'scanner'               => 'ComposerScanner',
					'reason'                => 'Some packages in installed.json do not have a name field',
					'file_path'             => $filePath,
					'packages_without_name' => $packagesWithoutName,
					'total_packages'        => count($packages)
				]);
			}
			
			return $extraMap;
		}
		
		/**
		 * Check if the array appears to be a direct array of packages
		 * @param array $data Data to check
		 * @return bool True if it looks like a package array
		 */
		private function isPackageArray(array $data): bool {
			// Check if it's a numerically indexed array with package-like objects
			if (empty($data) || !isset($data[0])) {
				return false;
			}
			
			// Check if the first element has package-like structure
			$firstElement = $data[0];
			return is_array($firstElement) && isset($firstElement['name']);
		}
	}