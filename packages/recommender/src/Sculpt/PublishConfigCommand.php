<?php
	
	namespace Quellabs\Recommender\Sculpt;
	
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/**
	 * Publishes the recommender configuration file to config/recommender.php in the project root.
	 *
	 * Usage:
	 *   sculpt recommender:init
	 */
	class PublishConfigCommand extends CommandBase {
		
		/**
		 * Return the command signature.
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return 'recommender:init';
		}
		
		/**
		 * Return the one-line command description shown in command listings.
		 * @return string One-line description of the command
		 */
		public function getDescription(): string {
			return 'Publish the recommender configuration file to config/recommender.php';
		}
		
		/**
		 * Copy the recommender config stub into the project config directory, unless it already exists.
		 * @param ConfigurationManager $config The Sculpt configuration manager (flags and arguments)
		 * @return int Exit code: 0 on success or when the file already exists, 1 on failure
		 */
		public function execute(ConfigurationManager $config): int {
			$source = realpath(__DIR__ . '/../../config/recommender.php');
			
			if ($source === false) {
				$this->output->error('Could not locate the recommender config stub file.');
				return 1;
			}
			
			$target = ComposerUtils::getProjectRoot() . '/config/recommender.php';
			
			// Keep an existing config so user edits are not overwritten
			if (file_exists($target)) {
				$this->output->success('Config file already exists, skipping');
				return 0;
			}
			
			$result = copy($source, $target);
			
			if ($result) {
				$this->output->success('Published config/recommender.php');
			} else {
				$this->output->error("Failed to copy config file to {$target}");
			}
			
			return $result ? 0 : 1;
		}
	}
