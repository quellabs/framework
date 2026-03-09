<?php
	
	namespace Quellabs\Sculpt\Contracts;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\IO\ConsoleInput;
	use Quellabs\Contracts\IO\ConsoleOutput;
	use Quellabs\Sculpt\ServiceProvider;
	
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
		 * @var ServiceProvider|null Optional service provider for dependency injection
		 */
		protected ?ProviderInterface $provider;
		
		/**
		 * @var string|null Cached $projectRoot
		 */
		protected ?string $projectRoot = null;
		
		/**
		 * Initialize a new command instance
		 * @param ConsoleInput $input Input handler to process command arguments and options
		 * @param ConsoleOutput $output Output handler to display results and messages
		 * @param ProviderInterface|null $provider Optional service provider for dependency injection
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ?ProviderInterface $provider = null) {
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
		 * Get the service provider instance if set
		 * @return ServiceProvider|null The service provider or null if not set
		 */
		public function getProvider(): ?ServiceProvider {
			return $this->provider;
		}
	}