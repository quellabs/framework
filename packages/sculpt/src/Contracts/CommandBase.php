<?php
	
	namespace Quellabs\Sculpt\Contracts;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\IO\ConsoleInput;
	use Quellabs\Contracts\IO\ConsoleOutput;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	
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
		protected ?ServiceProvider $provider;
		
		/**
		 * @var string|null Cached $projectRoot
		 */
		protected ?string $projectRoot = null;
		
		/**
		 * Initialize a new command instance
		 * @param ConsoleInput $input Input handler to process command arguments and options
		 * @param ConsoleOutput $output Output handler to display results and messages
		 * @param ServiceProvider|null $provider Optional service provider for dependency injection
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ?ServiceProvider $provider = null) {
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
		
		/**
		 * Determine the project root directory
		 *
		 * This method attempts to intelligently locate the project root by:
		 * 1. Starting at the current file's directory
		 * 2. Looking for a composer.json file by traversing up the directory tree
		 * 3. Using a maximum depth to prevent excessive recursion
		 * 4. Falling back to a reasonable default if composer.json can't be found
		 *
		 * Finding the project root is important for placing the Phinx config file
		 * at the correct location where Phinx will be able to find it.
		 * @return string The absolute path to the project root directory
		 */
		protected function determineProjectRoot(): string {
			// Fetch from cache if possible
			if ($this->projectRoot !== null) {
				return $this->projectRoot;
			}
			
			// Start at the current file's directory
			$currentDir = dirname(__FILE__);
			
			// Limit search depth to prevent excessive directory traversal
			$maxDepth = 5;
			
			// Traverse up the directory tree looking for composer.json
			for ($i = 0; $i < $maxDepth; $i++) {
				$parentDir = dirname($currentDir);
				
				// If we find composer.json, we've found the project root
				if (file_exists($parentDir . '/composer.json')) {
					return $this->projectRoot = $parentDir;
				}
				
				// We've reached the filesystem root with no success
				if ($parentDir === $currentDir) {
					break;
				}
				
				// Move up to the parent directory for the next iteration
				$currentDir = $parentDir;
			}
			
			// If no composer.json was found, use a sensible default
			return $this->projectRoot = dirname(__FILE__, 3);
		}
	}