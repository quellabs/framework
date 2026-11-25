<?php

	namespace Quellabs\Canvas\Blade;

	use Quellabs\Canvas\Blade\Sculpt\InitCommand;
	use Quellabs\Canvas\Blade\Sculpt\ClearCacheCommand;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Quellabs\Sculpt\Application;

	/**
	 * Blade Template Engine Service Provider for Canvas Framework
	 *
	 * Serves both the "di" and "sculpt" discovery categories declared in
	 * composer.json — it builds the real Blade instance for the DI container
	 * and registers this package's CLI commands with Sculpt. Extends
	 * Quellabs\Sculpt\ServiceProvider (for registerCommands()/register()) and
	 * implements ServiceProviderInterface directly instead of extending
	 * Quellabs\DependencyInjection\Provider\ServiceProvider, since PHP doesn't
	 * allow extending both — the DI Container only requires the interface
	 * (see Container::register()), not descent from that concrete class.
	 *
	 * @phpstan-type BladeConfig array{
	 *     template_dir: string,
	 *     cache_dir: string,
	 *     caching: bool,
	 *     paths: array<int|string, string>,
	 *     directives: array<string, callable>,
	 *     if_directives: array<string, callable>,
	 *     globals: array<string, mixed>
	 *  }
	 */
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider implements ServiceProviderInterface {

		/**
		 * @var BladeTemplate|null Instance of BladeTemplate
		 */
		private static ?BladeTemplate $instance = null;

		/**
		 * Returns the provider's metadata for service discovery
		 * @return array<string, mixed> Associative array containing provider metadata
		 */
		public static function getMetadata(): array {
			return [
				'provider'     => 'blade',                                          // Unique provider identifier
				'type'         => 'template_engine',                                // Service category
				'capabilities' => ['inheritance', 'components', 'directives', 'slots', 'stacks'], // Blade features
				'extensions'   => ['.blade.php'],                                   // Supported file extension
				'version'      => '1.0.0'                                           // Provider version
			];
		}

		/**
		 * Returns the default configuration settings for Blade
		 * @return BladeConfig
		 */
		public static function getDefaults(): array {
			$projectRoot = ComposerUtils::getProjectRoot();

			return [
				// Directory where Blade template files (.blade.php) are stored
				'template_dir'  => $projectRoot . '/templates/',

				// Directory where Blade stores compiled PHP templates
				'cache_dir'     => $projectRoot . '/storage/cache/blade/',

				// Enable/disable template caching for better performance
				'caching'       => true,

				// Custom Blade directives to register (name => callable returning PHP code)
				'directives'    => [],

				// Custom @if-directives to register (name => callable returning bool)
				'if_directives' => [],

				// Additional template directories with optional namespaces
				'paths'         => [],

				// Global variables available in all templates
				'globals'       => [],
			];
		}

		/**
		 * Merges user-provided configuration over the defaults
		 * @return BladeConfig
		 */
		public function mergeConfig(): array {
			$defaults = self::getDefaults();
			$configuration = $this->getConfig();

			/** @var array<int|string, string> $paths */
			$paths = is_array($configuration['paths'] ?? null) ? $configuration['paths'] : $defaults['paths'];

			/** @var array<string, callable> $directives */
			$directives = is_array($configuration['directives'] ?? null) ? $configuration['directives'] : $defaults['directives'];

			/** @var array<string, callable> $ifDirectives */
			$ifDirectives = is_array($configuration['if_directives'] ?? null) ? $configuration['if_directives'] : $defaults['if_directives'];

			/** @var array<string, mixed> $globals */
			$globals = is_array($configuration['globals'] ?? null) ? $configuration['globals'] : $defaults['globals'];

			return [
				'template_dir'  => $this->normalizeString($configuration['template_dir'] ?? null, $defaults['template_dir']),
				'cache_dir'     => $this->normalizeString($configuration['cache_dir'] ?? null, $defaults['cache_dir']),
				'caching'       => $this->normalizeBool($configuration['caching'] ?? null, $defaults['caching']),
				'paths'         => $paths,
				'directives'    => $directives,
				'if_directives' => $ifDirectives,
				'globals'       => $globals,
			];
		}

		/**
		 * Determines if this provider can handle the requested service
		 * @param string $className The interface/class name being requested
		 * @param array<string, mixed> $metadata Additional metadata from the service request
		 * @return bool True if this provider can handle the request
		 */
		public function supports(string $className, array $metadata): bool {
			// Only handle TemplateEngineInterface requests
			if ($className !== TemplateEngineInterface::class) {
				return false;
			}

			// Priority 1: Explicit provider request via context/metadata
			if (!empty($metadata['context'])) {
				return $metadata['context'] === 'blade';
			}

			// Priority 2: Check app.php configuration
			if ($this->hasConfigValue('template_engine')) {
				return $this->getConfigValue('template_engine') === 'blade';
			}

			// Return true to indicate this provider is available
			// The discovery system should handle "first encountered" logic
			return true;
		}

		/**
		 * Creates and configures a new Blade template engine instance
		 * @param string $className The requested interface (TemplateEngineInterface)
		 * @param array<string, mixed> $dependencies Resolved dependencies (unused in this case)
		 * @param array<string, mixed> $metadata Metadata as passed by Discover
		 * @param MethodContextInterface|null $methodContext
		 * @return object Configured BladeTemplate instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			// Return cached instance
			if (self::$instance !== null) {
				return self::$instance;
			}

			// Create BladeTemplate with merged configuration
			$instance = new BladeTemplate($this->mergeConfig());

			// Cache and return instance
			return self::$instance = $instance;
		}

		/**
		 * Register this package's commands with the Sculpt application
		 * @param Application $application The Sculpt application instance
		 * @return void
		 */
		public function register(Application $application): void {
			$this->registerCommands($application, [
				InitCommand::class,        // Publish the config file
				ClearCacheCommand::class,  // Register the blade:clear-cache command
			]);
		}

		/**
		 * Normalizes a config value to bool, accepting bool or int (0/1) as valid input
		 * @param mixed $value The raw value from user configuration
		 * @param bool $default Fallback when the value is absent or an unrecognized type
		 * @return bool
		 */
		private function normalizeBool(mixed $value, bool $default): bool {
			if (is_bool($value)) {
				return $value;
			} elseif (is_int($value)) {
				return (bool)$value;
			} else {
				return $default;
			}
		}

		/**
		 * Normalizes a config value to string
		 * @param mixed $value The raw value from user configuration
		 * @param string $default Fallback when the value is absent or an unrecognized type
		 * @return string
		 */
		private function normalizeString(mixed $value, string $default): string {
			if (is_string($value)) {
				return $value;
			} else {
				return $default;
			}
		}
	}
