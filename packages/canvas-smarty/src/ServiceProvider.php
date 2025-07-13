<?php
	
	namespace Quellabs\Canvas\Smarty;
	
	use Quellabs\Contracts\Context\MethodContext;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	
	/**
	 * Smarty Template Engine Service Provider for Canvas Framework
	 */
	class ServiceProvider extends \Quellabs\DependencyInjection\Provider\ServiceProvider {
		
		/**
		 * @var SmartyTemplate|null Instance of SmartyTemplate
		 */
		private static ?SmartyTemplate $instance = null;
		
		/**
		 * Returns the provider's metadata for service discovery
		 * @return array Associative array containing provider metadata
		 */
		public static function getMetadata(): array {
			return [
				'provider'     => 'smarty',                    // Unique provider identifier
				'type'         => 'template_engine',           // Service category
				'capabilities' => ['caching', 'inheritance', 'plugins'], // Smarty features
				'extensions'   => ['.tpl', '.smarty'],         // Supported file extensions
				'version'      => '1.0.0'                      // Provider version
			];
		}
		
		/**
		 * Returns the default configuration settings for Smarty
		 * @return array Default configuration array
		 */
		public static function getDefaults(): array {
			return [
				// Directory where Smarty template files (.tpl) are stored
				'template_dir'   => dirname(__FILE__) . '/../Templates/',
				
				// Directory where Smarty stores compiled templates for performance
				'compile_dir'    => dirname(__FILE__) . '/../Cache/Compile/',
				
				// Directory where Smarty stores cached template output
				'cache_dir'      => dirname(__FILE__) . '/../Cache/Cache/',
				
				// Enable/disable Smarty's debugging console
				'debugging'      => false,
				
				// Enable/disable template caching for better performance
				'caching'        => true,
				
				// Clear the compiled directory on cache flush
				'clear_compiled' => true,
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
			
			// If no specific provider is requested, we can handle it
			if (empty($metadata['provider'])) {
				return true;
			}
			
			// Only handle requests specifically asking for 'smarty' provider
			return $metadata['provider'] === 'smarty';
		}
		
		/**
		 * Creates and configures a new Smarty template engine instance
		 * @param string $className The requested interface (TemplateEngineInterface)
		 * @param array $dependencies Resolved dependencies (unused in this case)
		 * @param MethodContext|null $methodContext
		 * @return object Configured SmartyTemplate instance
		 */
		public function createInstance(string $className, array $dependencies, ?MethodContext $methodContext=null): object {
			// Return cached instance
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			// Get default configuration values
			$defaults = $this->getDefaults();
			
			// Get user-provided configuration (from config/smarty.php or similar)
			$configuration = $this->getConfig();
			
			// Create and return SmartyTemplate with merged configuration
			// User config takes precedence over defaults using null coalescing
			$instance = new SmartyTemplate([
				'template_dir'   => $configuration['template_dir'] ?? $defaults['template_dir'],
				'compile_dir'    => $configuration['compile_dir'] ?? $defaults['compile_dir'],
				'cache_dir'      => $configuration['cache_dir'] ?? $defaults['cache_dir'],
				'debugging'      => $configuration['debugging'] ?? $defaults['debugging'],
				'caching'        => $configuration['caching'] ?? $defaults['caching'],
				'clear_compiled' => $configuration['clear_compiled'] ?? $defaults['clear_compiled'],
				'cache_lifetime' => $configuration['cache_lifetime'] ?? null,
				'security'       => $configuration['security'] ?? null,
			]);
			
			// Add to cache and return
			return self::$instance = $instance;
		}
	}