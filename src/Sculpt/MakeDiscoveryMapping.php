<?php
	
	namespace App\Sculpt;
	
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/**
	 * Command to generate a discovery mapping file from monorepo packages
	 *
	 * This command scans the packages directory and creates a mapping file
	 * that helps with package discovery in the monorepo structure.
	 */
	class MakeDiscoveryMapping extends CommandBase {
		
		/**
		 * Get the command signature for CLI usage
		 * @return string The command signature used to invoke this command
		 */
		public function getSignature(): string {
			return "make:discovery-mapping";
		}
		
		/**
		 * Get the command description for help text
		 * @return string Human-readable description of what this command does
		 */
		public function getDescription(): string {
			return "Manually created a discovery mapping from the monorepo";
		}
		
		/**
		 * Execute the discovery mapping generation process
		 *
		 * This method:
		 * 1. Instantiates a PackageExtraExtractor to scan the packages directory
		 * 2. Extracts package information and creates a mapping
		 * 3. Writes the mapping to a configuration file
		 *
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code: 0 for success, 1 for failure
		 */
		public function execute(ConfigurationManager $config): int {
			try {
				// Create extractor instance pointing to the packages directory
				// Uses relative path from current file location
				$extractor = new PackageExtraExtractor(dirname(__FILE__) . '/../../packages');
				
				// Extract package information and generate a mapping array
				$map = $extractor->getMap();
				
				// Write the mapping to the discovery configuration file
				$extractor->writeExtraMapFile(dirname(__FILE__) . '/../../config/discovery-mapping.php', $map);
				
				// Output success message to user
				$this->output->success("Mapping file created");
				
				// Return success exit code
				return 0;
			} catch (\Exception $e) {
				// Handle any errors during the process
				$this->output->error($e->getMessage());
				
				// Return failure exit code
				return 1;
			}
		}
	}