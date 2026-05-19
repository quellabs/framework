<?php
	
	namespace Quellabs\Canvas\Handlebars;
	
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	
	/**
	 * Handlebars Template Engine Service Provider for Canvas Framework
	 *
	 * @phpstan-type HandlebarsConfig array{
	 *     template_dir: string,
	 *     compile_dir: string,
	 *     strict_mode: bool,
	 *     standalone: bool,
	 *     helpers: array<string, callable>,
	 *     partials: array<string, string>,
	 *     globals: array<string, mixed>
	 * }
	 */
	class ServiceProvider extends \Quellabs\DependencyInjection\Provider\ServiceProvider {
		
		/**
		 * @var HandlebarsTemplate|null Singleton instance
		 */
		private static ?HandlebarsTemplate $instance = null;
		
		/**
		 * Returns the provider's metadata for service discovery
		 * @return array<string, mixed> Associative array containing provider metadata
		 */
		public static function getMetadata(): array {
			return [
				'provider'     => 'handlebars',
				'type'         => 'template_engine',
				'capabilities' => ['helpers', 'partials', 'block-helpers', 'compiled-cache'],
				'extensions'   => ['.hbs', '.handlebars'],
				'version'      => '1.0.0'
			];
		}
		
		/**
		 * Returns the default configuration settings for Handlebars
		 * @return HandlebarsConfig
		 */
		public static function getDefaults(): array {
			return [
				// Directory where Handlebars template files (.hbs) are stored
				'template_dir' => dirname(__FILE__) . '/../Templates/',
				
				// Directory where LightnCandy stores compiled PHP renderers
				// Unlike Smarty's output cache, this is compiled PHP code
				'compile_dir'  => dirname(__FILE__) . '/../Cache/Compile/',
				
				// Enable strict mode: throw on missing variables instead of rendering empty
				'strict_mode'  => false,
				
				// Enable FLAG_BESTPERFORMANCE: compiled closures have no runtime dependency
				// Trade-off: helpers must be embedded at compile time (not passed at render)
				'standalone'   => false,
				
				// No helpers, partials, or globals by default
				'helpers'      => [],
				'partials'     => [],
				'globals'      => [],
			];
		}
		
		/**
		 * Merges user-provided configuration over the defaults
		 * @return HandlebarsConfig
		 */
		public function mergeConfig(): array {
			$defaults = self::getDefaults();
			$configuration = $this->getConfig();
			
			/** @var array<string, callable> $helpers */
			$helpers = is_array($configuration['helpers'] ?? null) ? $configuration['helpers'] : $defaults['helpers'];
			
			/** @var array<string, string> $partials */
			$partials = is_array($configuration['partials'] ?? null) ? $configuration['partials'] : $defaults['partials'];
			
			/** @var array<string, mixed> $globals */
			$globals = is_array($configuration['globals'] ?? null) ? $configuration['globals'] : $defaults['globals'];
			
			return [
				'template_dir' => $this->normalizeString($configuration['template_dir'] ?? null, $defaults['template_dir']),
				'compile_dir'  => $this->normalizeString($configuration['compile_dir'] ?? null, $defaults['compile_dir']),
				'strict_mode'  => $this->normalizeBool($configuration['strict_mode'] ?? null, $defaults['strict_mode']),
				'standalone'   => $this->normalizeBool($configuration['standalone'] ?? null, $defaults['standalone']),
				'helpers'      => $helpers,
				'partials'     => $partials,
				'globals'      => $globals,
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
			if (!empty($metadata['provider'])) {
				return $metadata['provider'] === 'handlebars';
			}
			
			// Priority 2: Check app.php configuration
			if ($this->hasConfigValue('template_engine')) {
				return $this->getConfigValue('template_engine') === 'handlebars';
			}
			
			// Return true to indicate this provider is available
			return true;
		}
		
		/**
		 * Creates and configures a new Handlebars template engine instance
		 * @param string $className The requested interface (TemplateEngineInterface)
		 * @param array<string, mixed> $dependencies Resolved dependencies
		 * @param array<string, mixed> $metadata Metadata as passed by Discover
		 * @param MethodContextInterface|null $methodContext
		 * @return object Configured HandlebarsTemplate instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			// Return cached instance
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			// Instantiate template engine
			$instance = new HandlebarsTemplate($this->mergeConfig());
			
			// Cache and return
			return self::$instance = $instance;
		}
	}