<?php
	
	namespace App;
	
	/**
	 * Canvas Project Installer
	 */
	class Installer {
		
		/**
		 * Main setup method that orchestrates the installation process
		 * @return void
		 */
		public static function setup(): void {
			// Create directory structure
			self::createDirectories();
			
			// Clean up - remove the installer
			self::cleanup();
		}
		
		/**
		 * Creates the complete directory structure for a Canvas project
		 * @return void
		 */
		private static function createDirectories(): void {
			// Define the directory structure as an associative array
			// Key = parent directory, Value = array of subdirectories
			$directories = [
				'storage'   => ['cache', 'sessions'],           // Storage for cache and session files
				'templates' => ['home'],                        // Template files directory
				'src'       => ['Controllers', 'Aspects'],      // Source code with MVC structure
				'config'    => [],                              // Configuration files
				'tests'     => ['Unit', 'Feature']              // Test directories for different test types
			];
			
			echo "Setting up Canvas project structure...\n";
			
			// Iterate through each parent directory
			foreach ($directories as $parent => $subdirectories) {
				// Create parent directory if it doesn't exist
				if (!is_dir($parent)) {
					// Create directory with read/write/execute for owner, read/execute for group/others
					mkdir($parent, 0755, true);
					echo "✓ Created {$parent}/\n";
				} else {
					echo "✓ Already exists {$parent}/\n";
				}
				
				// Create each subdirectory within the parent
				foreach ($subdirectories as $subdirectory) {
					$path = "{$parent}/{$subdirectory}";
					
					// Only create subdirectory if it doesn't already exist
					if (!is_dir($path)) {
						mkdir($path, 0755, true);
						echo "✓ Created {$path}/\n";
					} else {
						echo "✓ Already exists {$parent}/\n";
					}
				}
			}
			
			// Set special permissions for storage directory and its contents
			// Storage needs write permissions for the web server
			if (is_dir('storage')) {
				// Set storage directory to be writable by owner and group
				chmod('storage', 0775);
				
				// Apply same permissions to all subdirectories in storage
				foreach (glob('storage/*', GLOB_ONLYDIR) as $dir) {
					chmod($dir, 0775);
				}
				
				echo "✓ Set storage permissions\n";
			}
			
			// Display completion message and next steps
			echo "\n";
			echo " ██████╗ █████╗ ███╗   ██╗██╗   ██╗ █████╗ ███████╗\n";
			echo "██╔════╝██╔══██╗████╗  ██║██║   ██║██╔══██╗██╔════╝\n";
			echo "██║     ███████║██╔██╗ ██║██║   ██║███████║███████╗\n";
			echo "██║     ██╔══██║██║╚██╗██║╚██╗ ██╔╝██╔══██║╚════██║\n";
			echo "╚██████╗██║  ██║██║ ╚████║ ╚████╔╝ ██║  ██║███████║\n";
			echo "╚═════╝╚═╝  ╚═╝╚═╝  ╚═══╝  ╚═══╝  ╚═╝  ╚═╝╚══════╝\n";
			echo "\n";
			echo "Canvas project setup complete!\n";
			echo "\n";
			echo "Next steps:\n";
			echo "   1. Configure config/app.php\n";
			echo "   2. Edit src/Controllers/HomeController.php for your home route\n";
			echo "   3. Start server: php -S localhost:8000 -t public\n";
			echo "\n";
			echo "Documentation: https://canvasphp.com/docs\n";
			echo "\n";
		}
		
		/**
		 * Removes the installer file and cleans up temporary installation artifacts
		 * @return void
		 */
		private static function cleanup(): void {
			// Remove the installer file itself using the magic constant __FILE__
			if (file_exists(__FILE__)) {
				unlink(__FILE__);
				echo "Installer cleanup completed\n";
			}
		}
	}