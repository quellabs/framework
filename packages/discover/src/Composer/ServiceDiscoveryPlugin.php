<?php
	
	namespace Quellabs\Discover\Composer;
	
	use Composer\Composer;
	use Composer\Script\Event;
	use Composer\IO\IOInterface;
	use Composer\Plugin\PluginInterface;
	use Composer\EventDispatcher\EventSubscriberInterface;
	
	/**
	 * ServiceDiscoveryPlugin - A Composer plugin for automatic service discovery
	 *
	 * This plugin automatically generates a mapping file containing all "extra" data
	 * from installed packages. It runs after composer install/update commands and
	 * creates a PHP file with the aggregated extra data for service discovery purposes.
	 *
	 * The generated file is placed in config/discovery-mapping.php and contains
	 * an array mapping package names to their complete extra configuration blocks.
	 */
	class ServiceDiscoveryPlugin implements PluginInterface, EventSubscriberInterface {
		
		/**
		 * @var Composer The Composer instance
		 */
		private Composer $composer;
		
		/**
		 * @var IOInterface The IO interface for output messages
		 */
		private IOInterface $io;
		
		/**
		 * Activate the plugin
		 * @param Composer $composer The Composer instance
		 * @param IOInterface $io The IO interface for output
		 */
		public function activate(Composer $composer, IOInterface $io): void {
			$this->composer = $composer;
			$this->io = $io;
		}
		
		/**
		 * Deactivate the plugin
		 * @param Composer $composer The Composer instance
		 * @param IOInterface $io The IO interface
		 */
		public function deactivate(Composer $composer, IOInterface $io): void {
			@unlink($this->getOutputPath($composer));
		}
		
		/**
		 * Uninstall the plugin
		 * @param Composer $composer The Composer instance
		 * @param IOInterface $io The IO interface
		 */
		public function uninstall(Composer $composer, IOInterface $io): void {
			try {
				// Get the path to the generated mapping file
				$outputPath = $this->getOutputPath($composer);
				
				// Check if the file exists and remove it
				if (file_exists($outputPath)) {
					if (unlink($outputPath)) {
						$io->write('<info>Discover: Service discovery mapping file removed: ' . $outputPath . '</info>');
					} else {
						$io->writeError('<warning>Discover: Failed to remove service discovery mapping file: ' . $outputPath . '</warning>');
					}
				}
			} catch (\Exception $e) {
				$io->writeError('<error>Discover: Error during plugin deactivation: ' . $e->getMessage() . '</error>');
			}
		}
		
		/**
		 * Get the events this plugin subscribes to
		 * @return array Array of event names mapped to method names
		 */
		public static function getSubscribedEvents(): array {
			return [
				'post-install-cmd' => 'onPostInstall',  // After composer install
				'post-update-cmd'  => 'onPostUpdate',   // After composer update
			];
		}
		
		/**
		 * Handle the post-install event
		 * @param Event $event The Composer event object
		 */
		public function onPostInstall(Event $event) {
			$this->generateServiceMap($event->getComposer(), $event->getIO());
		}
		
		/**
		 * Handle the post-update event
		 * @param Event $event The Composer event object
		 */
		public function onPostUpdate(Event $event) {
			$this->generateServiceMap($event->getComposer(), $event->getIO());
		}
		
		/**
		 * Generate the service discovery mapping file
		 *
		 * This is the main method that orchestrates the service map generation process:
		 * 1. Reads the composer.lock file to get installed package data
		 * 2. Extracts "extra" data from all packages
		 * 3. Writes the aggregated data to a PHP file for runtime use
		 *
		 * @param Composer $composer The Composer instance
		 * @param IOInterface $io The IO interface for output messages
		 */
		private function generateServiceMap(Composer $composer, IOInterface $io): void {
			try {
				// Output message
				$io->write('<info>Discover: Building service discovery map from package metadata...</info>');
				
				// Get the lock file data - this contains the exact versions and metadata
				// of all installed packages as recorded during the last install/update
				$locker = $composer->getLocker();
				
				// Check if composer.lock exists - without it we can't generate the map
				if (!$locker->isLocked()) {
					$io->writeError('<warning>Discover: Cannot generate service discovery map: composer.lock file not found.</warning>');
					return;
				}
				
				// Read the lock file data which contains all package information
				$lockData = $locker->getLockData();
				
				// Extract the extra data from all packages in the lock file
				$extraMap = $this->extractServiceMap($lockData);
				
				// Skip file generation if no packages have extra data
				if (empty($extraMap)) {
					$io->write('<comment>Discover: No packages found with service discovery metadata (extra data).</comment>');
					return;
				}
				
				// Determine where to write the output file
				$outputPath = $this->getOutputPath($composer);
				
				// Create the output directory if it doesn't exist
				$outputDir = dirname($outputPath);
				
				if (!is_dir($outputDir)) {
					mkdir($outputDir, 0755, true);
				}
				
				// Write the extra map data to a PHP file
				$this->writeExtraMapFile($outputPath, $extraMap);
				
				// Show success messages with statistics
				$io->write("<info>Discover: Service discovery map generated: {$outputPath}</info>");
				$io->write("<comment>Discover: Mapped " . count($extraMap) . " packages with 'extra' metadata.</comment>");
				
			} catch (\Exception $e) {
				// Handle any errors that occur during the generation process
				$io->writeError("<error>Discover: Service discovery map generation failed: {$e->getMessage()}</error>");
			}
		}
		
		/**
		 * Get the output path for the extra map file
		 * @param Composer $composer The Composer instance
		 * @return string The full path where the mapping file should be written
		 */
		private function getOutputPath(Composer $composer): string {
			$config = $composer->getPackage()->getExtra()['discover'] ?? [];
			
			if (!empty($config['mapping-file'])) {
				// Fetch the mapping file
				$customPath = $config['mapping-file'];
				
				// Check if the path is absolute (handles both Unix and Windows)
				if ($this->isAbsolutePath($customPath)) {
					return $customPath;
				}
				
				// Handle relative paths
				$projectRoot = dirname($composer->getConfig()->getConfigSource()->getName());
				return $projectRoot . DIRECTORY_SEPARATOR . $customPath;
			}
			
			// Default fallback
			$projectRoot = dirname($composer->getConfig()->getConfigSource()->getName());
			return $projectRoot . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "discovery-mapping.php";
		}
		
		/**
		 * Check if a path is absolute (cross-platform)
		 * @param string $path The path to check
		 * @return bool True if the path is absolute, false otherwise
		 */
		private function isAbsolutePath(string $path): bool {
			// Unix-style absolute path
			if (str_starts_with($path, '/')) {
				return true;
			}
			// Windows-style absolute path (C:\, D:\, etc.) and not an absolute path
			return PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:[\/\\\\]/', $path);
		}
		
		/**
		 * Extract extra data from the service discovery config file
		 * @param array $lockData
		 * @return array Associative array mapping package names to their extra data
		 */
		private function extractServiceMap(array $lockData): array {
			$extraMap = [];
			
			// Process main packages only (no dev packages)
			// The 'packages' key contains production dependencies
			if (isset($lockData['packages'])) {
				foreach ($lockData['packages'] as $package) {
					// Skip invalid packages  (shouldn't happen but safety first)
					if (
						empty($package['name']) ||
						empty($package['extra']) ||
						!is_array($package['extra'])
					) {
						continue;
					}
					
					// Filter out 'thanks' and 'branch-alias' keys
					$mapEntry = array_filter($package['extra'], function ($key) {
						return !in_array($key, ['thanks', 'branch-alias', 'class']);
					}, ARRAY_FILTER_USE_KEY);
					
					// Skip entry if there are no other entries
					if (empty($mapEntry)) {
						continue;
					}
					
					// Add to the map
					$extraMap[$package['name']] = $mapEntry;
				}
			}
			
			return $extraMap;
		}
		
		/**
		 * Write the extra map to a PHP file
		 * @param string $outputPath Full path where the file should be written
		 * @param array $extraMap The complete mapping of package names to extra data
		 * @throws \RuntimeException If the file cannot be written
		 */
		private function writeExtraMapFile(string $outputPath, array $extraMap): void {
			$timestamp = date('Y-m-d H:i:s');
			$count = count($extraMap);
			
			// Generate the PHP file header with metadata
			$content = <<<PHP
<?php
/**
 * Auto-generated by Discover
 * Generated on: {$timestamp}
 * Total packages with extra data: {$count}
 *
 * This file is automatically generated by the ServiceDiscoveryPlugin.
 * Do not edit manually as changes will be overwritten.
 *
 * Format: "package_name" => complete extra block
 */

return
PHP;
			
			// Add the formatted array data
			$content .= $this->varExportPretty($extraMap, 0);
			$content .= ";\n";
			
			// Write the content to the file
			if (file_put_contents($outputPath, $content) === false) {
				throw new \RuntimeException("Failed to write extra map to: {$outputPath}");
			}
		}
		
		/**
		 * Pretty print var_export with proper indentation
		 * @param mixed $var The variable to format (typically an array)
		 * @param int $indent Current indentation level (used for recursion)
		 * @return string Formatted PHP code representation of the variable
		 */
		private function varExportPretty(mixed $var, int $indent = 0): string {
			// Create the indentation string based on current level
			$indentStr = str_repeat('    ', $indent);
			
			if (is_array($var)) {
				// Handle empty arrays
				if (empty($var)) {
					return '[]';
				}
				
				// Determine if this is an associative array or indexed array
				$isAssoc = array_keys($var) !== range(0, count($var) - 1);
				$output = "[\n";
				
				// Process each array element
				foreach ($var as $key => $value) {
					$output .= $indentStr . '    ';
					
					// For associative arrays, include the key
					if ($isAssoc) {
						$output .= var_export($key, true) . ' => ';
					}
					
					// Recursively format the value with increased indentation
					$output .= $this->varExportPretty($value, $indent + 1);
					$output .= ",\n";
				}
				
				$output .= $indentStr . ']';
				return $output;
			}
			
			// For non-arrays, use standard var_export
			return var_export($var, true);
		}
	}