<?php
	
	namespace Quellabs\Canvas\Sculpt;
	
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Support\ComposerUtils;
	
	class RoutesCacheClearCommand extends RoutesBase {
		
		/**
		 * Returns the signature of this command
		 * @return string
		 */
		public function getSignature(): string {
			return "route:clear-cache";
		}
		
		/**
		 * Returns a brief description of what this command is for
		 * @return string
		 */
		public function getDescription(): string {
			return "Clear routing cache";
		}
		
		/**
		 * List the routes
		 * @param ConfigurationManager $config
		 * @return int
		 */
		public function execute(ConfigurationManager $config): int {
			// Fetch contents of app.php
			$providerConfig = $this->getProvider()->getConfig();
			
			// Determine the cache directory
			$cacheDirectory = $providerConfig['cache_dir'] ?? ComposerUtils::getProjectRoot();
			
			// Remove the cache file
			@unlink($cacheDirectory . "/storage/cache/routes.serialized");
			
			// Show message
			$this->output->success("Routes cache cleared");
			
			// Return success status
			return 0;
		}
	}