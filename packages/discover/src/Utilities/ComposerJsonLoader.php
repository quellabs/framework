<?php
	
	namespace Quellabs\Discover\Utilities;
	
	use Psr\Log\NullLogger;
	use Psr\Log\LoggerInterface;
	use Quellabs\Support\ComposerUtils;
	
	/**
	 * Handles loading and parsing the main composer.json configuration file
	 * Uses PSR4 utility class for file path resolution
	 */
	class ComposerJsonLoader {
		
		/**
		 * @var array|null Cached composer.json data
		 */
		private ?array $composerJsonCache = null;
		
		/**
		 * @var LoggerInterface
		 */
		private LoggerInterface $logger;
		
		/**
		 * ComposerJsonLoader constructor
		 * @param LoggerInterface|null $logger Logger instance (uses NullLogger if not provided)
		 */
		public function __construct(?LoggerInterface $logger = null) {
			$this->logger = $logger ?? new NullLogger();
		}
		
		/**
		 * Parse and return composer.json data with caching
		 * @param string|null $startDirectory Directory to start searching from (defaults to current directory)
		 * @return array|null Parsed composer.json data or null on failure
		 */
		public function getData(?string $startDirectory = null): ?array {
			// Return cached result if available
			if ($this->composerJsonCache !== null) {
				return $this->composerJsonCache;
			}
			
			// Use pathResolver to locate the composer.json file
			$composerJsonPath = ComposerUtils::getComposerJsonFilePath($startDirectory);
			
			if ($composerJsonPath === null) {
				// Silent return - composer.json is optional and may not exist in valid scenarios
				return null;
			}
			
			// Parse and cache the result
			return $this->composerJsonCache = [
				'local' => $this->parseJsonFile($composerJsonPath)
			];
		}
		
		/**
		 * Parse a JSON file and return its contents as an array
		 * @param string $filePath Path to the JSON file
		 * @return array|null Parsed JSON data or null on failure
		 */
		protected function parseJsonFile(string $filePath): ?array {
			// Check if the file exists and is readable
			if (!is_readable($filePath)) {
				$this->logger->warning('composer.json not readable', [
					'scanner'          => 'ComposerJsonLoader',
					'reason'           => 'File exists but is not readable (permission issue)',
					'file_path'        => $filePath,
					'file_exists'      => file_exists($filePath),
					'file_permissions' => file_exists($filePath) ? decoct(fileperms($filePath) & 0777) : 'N/A'
				]);
				
				return null;
			}
			
			// Read the entire file contents into a string
			$content = file_get_contents($filePath);
			
			// Check if file reading was successful
			if ($content === false) {
				$this->logger->warning('Failed to read composer.json contents', [
					'scanner'     => 'ComposerJsonLoader',
					'reason'      => 'file_get_contents() returned false',
					'file_path'   => $filePath,
					'file_size'   => file_exists($filePath) ? filesize($filePath) : 'N/A'
				]);
				
				return null;
			}
			
			// Decode the JSON string into a PHP array
			// The second parameter 'true' ensures we get an associative array instead of objects
			$data = json_decode($content, true);
			
			// Check if JSON parsing was successful by examining the last JSON error
			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->logger->warning('composer.json JSON parsing failed', [
					'scanner'         => 'ComposerJsonLoader',
					'reason'          => 'Invalid JSON syntax in composer.json',
					'file_path'       => $filePath,
					'json_error'      => json_last_error_msg(),
					'json_error_code' => json_last_error(),
				]);
				
				return null;
			}
			
			// Validate the existence of an 'extra' section
			if (empty($data['extra'])) {
				return [];
			}
			
			// Return the extra section
			return $data['extra'];
		}
	}