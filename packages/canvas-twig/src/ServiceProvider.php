<?php
	
	namespace Quellabs\Canvas\Twig;
	
	use Quellabs\Contracts\Context\MethodContext;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Quellabs\Discover\Discover;
	use Quellabs\Support\ComposerUtils;
	
	/**
	 * Twig Template Engine Service Provider for Canvas Framework
	 */
	class ServiceProvider extends \Quellabs\DependencyInjection\Provider\ServiceProvider {
		
		/**
		 * @var TwigTemplate|null Instance of TwigTemplate
		 */
		private static ?TwigTemplate $instance = null;
		
		/**
		 * Returns the provider's metadata for service discovery
		 * @return array Associative array containing provider metadata
		 */
		public static function getMetadata(): array {
			return [
				'provider'     => 'twig',                         // Unique provider identifier
				'type'         => 'template_engine',              // Service category
				'capabilities' => ['caching', 'inheritance', 'filters', 'functions', 'extensions'], // Twig features
				'extensions'   => ['.twig', '.html.twig'],        // Supported file extensions
				'version'      => '1.0.0'                         // Provider version
			];
		}
		
		/**
		 * Returns the default configuration settings for Twig
		 * @return array Default configuration array
		 */
		public static function getDefaults(): array {
			$projectRoot = ComposerUtils::getProjectRoot();
			
			return [
				// Directory where Twig template files (.twig) are stored
				'template_dir'     => $projectRoot . '/templates/',
				
				// Directory where Twig stores cached compiled templates
				'cache_dir'        => $projectRoot . '/storage/cache/twig/',
				
				// Enable/disable Twig's debug mode
				'debugging'        => false,
				
				// Enable/disable template caching for better performance
				'caching'          => true,
				
				// Auto-reload templates when they change (useful in development)
				'auto_reload'      => true,
				
				// Throw errors when undefined variables are accessed
				'strict_variables' => false,
				
				// Character set for template output
				'charset'          => 'UTF-8',
				
				// Enable/disable automatic HTML escaping
				'autoescape'       => 'html',
				
				// Custom Twig extensions to load
				'extensions'       => [],
				
				// Additional template directories with optional namespaces
				'paths'            => [],
			];
		}
		
		/**
		 * Determines if this provider can handle the requested service
		 * @param string $className The interface/class name being requested
		 * @param array $metadata Additional metadata from the service request
		 * @return bool True if this provider can handle the request
		 */
		public function supports(string $className, array $metadata): bool {
			// Only handle TemplateEngineInterface requests
			if ($className !== TemplateEngineInterface::class) {
				return false;
			}
			
			// Priority 1: Explicit provider request via context/metadata
			if (!empty($metadata['provider'])) {
				return $metadata['provider'] === 'twig';
			}
			
			// Priority 2: Check app.php configuration
			if ($this->hasConfigValue('template_engine')) {
				return $this->getConfigValue('template_engine') === 'twig';
			}
			
			// Return true to indicate this provider is available
			// The discovery system should handle "first encountered" logic
			return true;
		}
		
		/**
		 * Creates and configures a new Twig template engine instance
		 * @param string $className The requested interface (TemplateEngineInterface)
		 * @param array $dependencies Resolved dependencies (unused in this case)
		 * @param array $metadata Metadata as passed by Discover
		 * @param MethodContext|null $methodContext
		 * @return object Configured TwigTemplate instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContext $methodContext = null): object {
			// Return cached instance
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			// Get default configuration values
			$defaults = $this->getDefaults();
			
			// Get user-provided configuration (from config/twig.php or similar)
			$configuration = $this->getConfig();
			
			// Create TwigTemplate with merged configuration
			// User config takes precedence over defaults using null coalescing
			$instance = new TwigTemplate([
				'template_dir'     => $configuration['template_dir'] ?? $defaults['template_dir'],
				'cache_dir'        => $configuration['cache_dir'] ?? $defaults['cache_dir'],
				'debugging'        => $configuration['debugging'] ?? $defaults['debugging'],
				'caching'          => $configuration['caching'] ?? $defaults['caching'],
				'auto_reload'      => $configuration['auto_reload'] ?? $defaults['auto_reload'],
				'strict_variables' => $configuration['strict_variables'] ?? $defaults['strict_variables'],
				'charset'          => $configuration['charset'] ?? $defaults['charset'],
				'autoescape'       => $configuration['autoescape'] ?? $defaults['autoescape'],
				'extensions'       => $configuration['extensions'] ?? $defaults['extensions'],
				'paths'            => $configuration['paths'] ?? $defaults['paths'],
			]);
			
			// Add additional paths if configured
			if (!empty($configuration['paths'])) {
				foreach ($configuration['paths'] as $namespace => $path) {
					if (is_string($namespace)) {
						$instance->addPath($path, $namespace); // Namespaced path
					} else {
						$instance->addPath($path); // Non-namespaced path
					}
				}
			}
			
			// Register custom functions if configured
			if (!empty($configuration['functions'])) {
				foreach ($configuration['functions'] as $name => $callback) {
					if (is_callable($callback)) {
						$instance->registerFunction($name, $callback);
					}
				}
			}
			
			// Register custom filters if configured
			if (!empty($configuration['filters'])) {
				foreach ($configuration['filters'] as $name => $callback) {
					if (is_callable($callback)) {
						$instance->registerFilter($name, $callback);
					}
				}
			}
			
			// Add global variables if configured
			if (!empty($configuration['globals'])) {
				foreach ($configuration['globals'] as $key => $value) {
					$instance->addGlobal($key, $value);
				}
			}
			
			// Cache and return instance
			return self::$instance = $instance;
		}
	}