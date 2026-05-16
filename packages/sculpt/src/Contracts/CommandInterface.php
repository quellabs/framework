<?php
	
	namespace Quellabs\Sculpt\Contracts;
	
	use Quellabs\Sculpt\ConfigurationManager;
	
	/**
	 * Interface for all command classes in the Sculpt CLI framework
	 */
	interface CommandInterface {
		/**
		 * Returns the command signature that identifies it in the CLI
		 * @return string
		 */
		public function getSignature(): string;
		
		/**
		 * Returns a short description of what the command does
		 * @return string
		 */
		public function getDescription(): string;
		
		/**
		 * Returns detailed help text for the command
		 * @return string
		 */
		public function getHelp(): string;
		
		/**
		 * Executes the command with the given arguments
		 * @param ConfigurationManager $config
		 * @return int Exit code
		 */
		public function execute(ConfigurationManager $config): int;
		
		/**
		 * Returns the service provider that registered this command, if any
		 * @return mixed|null
		 */
		public function getProvider(): mixed;
	}