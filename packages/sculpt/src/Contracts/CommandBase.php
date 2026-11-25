<?php
	
	namespace Quellabs\Sculpt\Contracts;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\IO\ConsoleInput;
	use Quellabs\Contracts\IO\ConsoleOutput;
	
	/**
	 * Abstract base class for all command implementations
	 *
	 * Provides core functionality and property management for console commands.
	 * All concrete command classes should extend this class and implement
	 * required methods from CommandInterface.
	 */
	abstract class CommandBase implements CommandInterface {
		
		/**
		 * @var ConsoleInput Input handler for the command
		 */
		protected ConsoleInput $input;
		
		/**
		 * @var ConsoleOutput Output handler for the command
		 */
		protected ConsoleOutput $output;
		
		/**
		 * @var ProviderInterface Service provider
		 */
		protected ProviderInterface $provider;
		
		/**
		 * @var string|null Cached $projectRoot
		 */
		protected ?string $projectRoot = null;
		
		/**
		 * Initialize a new command instance
		 * @param ConsoleInput $input Input handler to process command arguments and options
		 * @param ConsoleOutput $output Output handler to display results and messages
		 * @param ProviderInterface $provider Service provider
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ProviderInterface $provider) {
			$this->input = $input;
			$this->output = $output;
			$this->provider = $provider;
		}
		
		/**
		 * Get the input handler instance
		 * @return ConsoleInput The input handler for this command
		 */
		public function getInput(): ConsoleInput {
			return $this->input;
		}
		
		/**
		 * Get the output handler instance
		 * @return ConsoleOutput The output handler for this command
		 */
		public function getOutput(): ConsoleOutput {
			return $this->output;
		}
		
		/**
		 * Returns detailed help text for the command.
		 * Override in concrete commands to provide usage instructions.
		 * @return string
		 */
		public function getHelp(): string {
			return '';
		}
		
		/**
		 * Get the service provider instance if set
		 * @return ProviderInterface The service provider
		 */
		public function getProvider(): ProviderInterface {
			return $this->provider;
		}
	}