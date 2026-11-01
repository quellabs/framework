<?php

	namespace Quellabs\CanvasDoctrine\Sculpt;

	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Support\ComposerUtils;

	/**
	 * Publishes the Doctrine configuration stub.
	 */
	class PublishConfigCommand extends CommandBase {
		
		/**
		 * Returns the command name.
		 * @return string
		 */
		public function getSignature(): string {
			return 'doctrine:init';
		}

		/**
		 * Returns the command description.
		 * @return string
		 */
		public function getDescription(): string {
			return 'Publish config/doctrine.php';
		}

		/**
		 * Copies the stub into the application config directory.
		 * @param ConfigurationManager $config
		 * @return int
		 */
		public function execute(ConfigurationManager $config): int {
			$source = __DIR__ . '/../../config/doctrine.php';
			$target = ComposerUtils::getProjectRoot() . '/config/doctrine.php';
		
			if (file_exists($target)) {
				$this->output->success('Config file already exists, skipping');
				return 0;
			}
		
			if (!is_file($source) || !copy($source, $target)) {
				$this->output->error("Failed to publish {$target}");
				return 1;
			}
		
			$this->output->success('Published config/doctrine.php');
			return 0;
		}
	}
