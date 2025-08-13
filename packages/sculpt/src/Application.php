<?php
	
	/*
	 * ╔═══════════════════════════════════════════════════════════════════════════════════════╗
	 * ║                                                                                       ║
	 * ║  ███████╗ ██████╗██╗   ██╗██╗     ██████╗ ████████╗                                   ║
	 * ║  ██╔════╝██╔════╝██║   ██║██║     ██╔══██╗╚══██╔══╝                                   ║
	 * ║  ███████╗██║     ██║   ██║██║     ██████╔╝   ██║                                      ║
	 * ║  ╚════██║██║     ██║   ██║██║     ██╔═══╝    ██║                                      ║
	 * ║  ███████║╚██████╗╚██████╔╝███████╗██║        ██║                                      ║
	 * ║  ╚══════╝ ╚═════╝ ╚═════╝ ╚══════╝╚═╝        ╚═╝                                      ║
	 * ║                                                                                       ║
	 * ║  Sculpt - Modern CLI framework for the Quellabs ecosystem                             ║
	 * ║                                                                                       ║
	 * ║  Provides an elegant command-line interface for rapid development, code generation,   ║
	 * ║  and project management with seamless ObjectQuel ORM integration.                     ║
	 * ║                                                                                       ║
	 * ╚═══════════════════════════════════════════════════════════════════════════════════════╝
	 */
	
	namespace Quellabs\Sculpt;
	
	use Quellabs\Discover\Discover;
	use Quellabs\Contracts\IO\ConsoleInput;
	use Quellabs\Contracts\IO\ConsoleOutput;
	use Quellabs\Discover\Scanner\ComposerScanner;
	use Quellabs\Sculpt\Contracts\CommandInterface;
	
	/**
	 * This is the main entry point for the Sculpt CLI application, responsible for
	 * discovering and registering service providers from installed packages,
	 * registering and executing commands.
	 */
	class Application {
		
		/**
		 * @var string|null
		 */
		private ?string $basePath;
		
		/**
		 * Registered command instances indexed by their signature
		 * Commands are the primary way users interact with the application
		 */
		protected array $commands = [];
		
		/**
		 * Console input handler for reading user input
		 */
		protected ConsoleInput $input;
		
		/**
		 * Console output handler for displaying results to the user
		 */
		protected ConsoleOutput $output;
		
		/**
		 * Provider manager responsible for service provider discovery and management
		 */
		protected Discover $serviceDiscoverer;
		
		/**
		 * Application constructor
		 * @param ConsoleInput $input Handler for reading from console
		 * @param ConsoleOutput $output Handler for writing to console
		 * @param string|null $basePath
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ?string $basePath = null) {
			// Store input and output classes
			$this->input = $input;
			$this->output = $output;
			
			// Set base path (where src directory is located)
			if ($basePath === null) {
				// Default to 2 directories up from this file (assuming this file is in src/Application.php)
				$this->basePath = dirname(__DIR__);
			} else {
				$this->basePath = $basePath;
			}
			
			// Initialize the provider manager
			$this->serviceDiscoverer = new Discover();
			$this->serviceDiscoverer->addScanner(new ComposerScanner("sculpt"));
			
			// Register internal commands
			$this->discoverInternalCommands();
		}
		
		/**
		 * Returns the input class
		 * @return ConsoleInput
		 */
		public function getInput(): ConsoleInput {
			return $this->input;
		}
		
		/**
		 * Returns the output class
		 * @return ConsoleOutput
		 */
		public function getOutput(): ConsoleOutput {
			return $this->output;
		}
		
		/**
		 * Get the service discoverer instance
		 * @return Discover
		 */
		public function getServiceDiscoverer(): Discover {
			return $this->serviceDiscoverer;
		}
		
		/**
		 * Returns the base path of the application
		 * @return string
		 */
		public function getBasePath(): string {
			return $this->basePath;
		}
		
		/**
		 * Automatically finds and registers all command classes in the built-in
		 * Commands directory. This provides the core functionality of the framework.
		 * @return void
		 */
		public function discoverInternalCommands(): void {
			// Define the namespace prefix for internal commands
			$namespace = 'Quellabs\\Sculpt\\Commands\\';
			
			// Get the absolute path to the commands directory
			$commandsDir = $this->getBasePath() . '/src/Commands';
			
			// Handle gracefully if the directory doesn't exist
			// This prevents errors if the directory structure changes
			if (!is_dir($commandsDir)) {
				return;
			}
			
			// Iterate through files in the commands directory
			foreach (new \DirectoryIterator($commandsDir) as $file) {
				// Skip directory navigation entries (. and ..), subdirectories, and non-PHP files
				// This ensures we only process actual command class files
				if ($file->isDot() || $file->isDir() || $file->getExtension() !== 'php') {
					continue;
				}
				
				// Construct the fully qualified class name
				// Combines the namespace prefix with the filename (without extension)
				$className = $namespace . pathinfo($file->getFilename(), PATHINFO_FILENAME);
				
				// Instantiate and register command classes that implement CommandInterface
				// This validation ensures only proper command classes are registered
				if (class_exists($className) && is_subclass_of($className, CommandInterface::class)) {
					// Create a new instance of the command with required dependencies
					$command = new $className($this->input, $this->output, $this);
					
					// Register the command in the commands collection using its signature as the key
					// This allows commands to be looked up by their signature (e.g., "db:migrate")
					$this->commands[$command->getSignature()] = $command;
				}
			}
		}
		
		/**
		 * Discover and register service providers from installed packages
		 * @return void
		 */
		public function discoverProviders(): void {
			// Delegate to the service discoverer
			$this->getServiceDiscoverer()->discover();
			
			// Call register on all found providers
			foreach($this->getServiceDiscoverer()->getProviders() as $provider) {
				$provider->register($this);
			}
		}
		
		/**
		 * Adds a command to the list of available commands, indexed by its signature.
		 * Commands are the primary way users interact with the application.
		 * @param CommandInterface $command The command instance to register
		 */
		public function registerCommand(CommandInterface $command): void {
			$this->commands[$command->getSignature()] = $command;
		}
		
		/**
		 * Verifies if a command with the given signature is registered in the application.
		 * @param string $name The command signature to check
		 * @return bool True if the command exists, false otherwise
		 */
		public function hasCommand(string $name): bool {
			return isset($this->commands[$name]);
		}
		
		/**
		 * Retrieves a registered command by its signature.
		 * @param string $name The command signature
		 * @return CommandInterface|null The command instance or null if not found
		 */
		public function getCommand(string $name): ?CommandInterface {
			return $this->commands[$name] ?? null;
		}
		
		/**
		 * Parses command-line arguments, finds the requested command,
		 * and executes it. If no command is specified or the command is not found,
		 * it displays the list of available commands.
		 * @param array $args Command-line arguments (typically from $argv)
		 * @return int Exit code (0 for success, non-zero for errors)
		 */
		public function run(array $args = []): int {
			// First argument after script name is the command
			$commandName = $args[1] ?? null;
			
			// If no command specified, show available commands
			if (!$commandName) {
				return $this->listCommands();
			}
			
			// Check for help command format: "help command:x"
			if ($commandName === 'help' && isset($args[2])) {
				$targetCommandName = $args[2];
				
				// Check if the target command exists
				if (!$this->hasCommand($targetCommandName)) {
					$this->output->warning("Command '{$targetCommandName}' not found.");
					return $this->listCommands();
				}
				
				// Display help for the target command
				return $this->displayCommandHelp($targetCommandName);
			}
			
			// Check if command exists
			if (!$this->hasCommand($commandName)) {
				$this->output->warning("Command '{$commandName}' not found.");
				return $this->listCommands();
			}
			
			// Get the command and remaining arguments
			$command = $this->commands[$commandName];
			$commandArgs = array_slice($args, 2);
			
			// Create a configuration manager with the command arguments
			$config = new ConfigurationManager($commandArgs);
			
			// Execute command with the configuration manager
			try {
				return $command->execute($config);
			} catch (\Exception $e) {
				// Handle exceptions from command execution
				$this->output->error($e->getMessage());
				return 1;
			}
		}
		
		/**
		 * Display detailed help for a specific command
		 * @param string $commandName The command signature
		 * @return int Exit code
		 */
		protected function displayCommandHelp(string $commandName): int {
			$command = $this->commands[$commandName];
			
			// Display command header
			$this->output->writeLn("\n<bold><white>Help: {$commandName}</white></bold>");
			$this->output->writeLn("<dim>{$command->getDescription()}</dim>\n");
			
			// Display help text from the command's getHelp() method
			$helpText = $command->getHelp();
			
			if (!empty($helpText)) {
				$this->output->writeLn($helpText);
			} else {
				$this->output->writeLn("No detailed help available for this command.");
			}
			
			$this->output->writeLn(''); // Add a blank line at the end
			
			return 0;
		}
		
		/**
		 * Displays a formatted list of all registered commands, grouped by command namespace
		 * (the part before the colon in the command signature)
		 * @return int Exit code
		 */
		protected function listCommands(): int {
			// Group commands by command namespace (part before the colon)
			$namespaceGroups = [];
			
			// Calculate the maximum signature length across ALL commands for consistent alignment
			$maxSignatureLength = 0;
			
			// First collect all commands and organize them by namespace
			foreach ($this->commands as $signature => $command) {
				// Split signature by colon to get the namespace part
				$parts = explode(':', $signature, 2);
				$namespace = $parts[0];
				
				// Add command to its namespace group
				$namespaceGroups[$namespace][$signature] = $command;
				
				// Track the longest signature for global alignment
				$maxSignatureLength = max($maxSignatureLength, strlen($signature));
			}
			
			// Sort namespace groups alphabetically
			ksort($namespaceGroups);
			
			// Begin output with a header
			$this->output->writeLn("\n<bold><white>Sculpt CLI</white></bold> - Command Line Interface\n");
			
			// Display commands for each namespace group
			foreach ($namespaceGroups as $namespace => $commands) {
				// Display the namespace header
				$this->output->writeLn("<bg_cyan><black>[{$namespace}]</black></bg_cyan>");
				
				// Sort commands within each namespace group
				ksort($commands);
				
				// Display each command in the current namespace group
				foreach ($commands as $signature => $command) {
					// Use the global max signature length for consistent padding
					$padding = str_repeat(' ', $maxSignatureLength - strlen($signature) + 4);
					$this->output->writeLn("  <green>{$signature}</green>{$padding}{$command->getDescription()}");
				}
				
				// Add blank line after each namespace group
				$this->output->writeLn("");
			}
			
			// Display usage instructions at the end
			$this->output->writeLn("<bold>Usage:</bold>");
			$this->output->writeLn("  sculpt <green>command</green> [arguments]");
			$this->output->writeLn("  sculpt <green>help</green> command   <dim>(for detailed help)</dim>");
			
			// Return success code
			return 0;
		}
	}