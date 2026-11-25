<?php
	
	namespace Quellabs\Sculpt\Contracts;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\IO\ConsoleInput;
	use Quellabs\Contracts\IO\ConsoleOutput;
	use Quellabs\Discover\Discover;
	use Quellabs\Discover\Scanner\ComposerScanner;

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
		 * @var Discover|null Lazily-built "di" family discoverer, shared across every command instance in this process
		 */
		private static ?Discover $diDiscoverer = null;

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

		/**
		 * Resolves a package's "di" category provider by class name, fully
		 * instantiated and configured from its own declared config files.
		 *
		 * getProvider() (and $this->provider) is always the "sculpt" category
		 * provider declared in composer.json's extra.discover.sculpt key — a
		 * separate class from the "di" category provider a package's commands
		 * often need for its actual runtime configuration (e.g. a template
		 * engine's directories). This resolves that "di" provider instead.
		 * @template T of ProviderInterface
		 * @param class-string<T> $className The "di" provider class to resolve
		 * @return T|null The configured provider, or null if not discovered
		 */
		protected function resolveDiProvider(string $className): ?ProviderInterface {
			if (self::$diDiscoverer === null) {
				self::$diDiscoverer = new Discover();
				self::$diDiscoverer->addScanner(new ComposerScanner('di'));
				self::$diDiscoverer->discover();
			}

			return self::$diDiscoverer->get($className);
		}
	}