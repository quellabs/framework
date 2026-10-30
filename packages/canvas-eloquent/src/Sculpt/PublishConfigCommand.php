<?php

	namespace Quellabs\CanvasEloquent\Sculpt;

	use Quellabs\Support\ComposerUtils;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;

	/**
	 * Publishes the Eloquent configuration file to config/eloquent.php
	 * in the project root.
	 *
	 * Usage:
	 *   sculpt eloquent:init
	 */
	class PublishConfigCommand extends CommandBase {

		/**
		 * Return the command's invocable signature.
		 * @return string The command signature (its invocable name)
		 */
		public function getSignature(): string {
			return 'eloquent:init';
		}

		/**
		 * Return the one-line command description shown in command listings.
		 * @return string One-line description of the command
		 */
		public function getDescription(): string {
			return 'Publish the Eloquent configuration file to config/eloquent.php';
		}

		/**
		 * Publish the Eloquent config stub into the project config directory.
		 * @param ConfigurationManager $config The Sculpt configuration manager (flags and arguments)
		 * @return int Exit code: 0 on success, 1 on failure
		 */
		public function execute(ConfigurationManager $config): int {
			$source = realpath(__DIR__ . '/../../config/eloquent.php');

			if ($source === false) {
				$this->output->error('Could not locate the Eloquent config stub file.');
				return 1;
			}

			$target = ComposerUtils::getProjectRoot() . '/config/eloquent.php';

			// Skip if the config file was already published
			if (file_exists($target)) {
				$this->output->success('Config file already exists, skipping');
				return 0;
			}

			$result = copy($source, $target);

			if ($result) {
				$this->output->success('Published config/eloquent.php');
			} else {
				$this->output->error("Failed to copy config file to {$target}");
			}

			return $result ? 0 : 1;
		}
	}
