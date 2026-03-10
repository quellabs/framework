<?php
	
	namespace Quellabs\Canvas\Blade;
	
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Contracts\Context\MethodContext;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	
	/**
	 * Blade Template Engine Service Provider for Canvas Framework
	 */
	class ServiceProvider extends \Quellabs\DependencyInjection\Provider\ServiceProvider {
		
		/**
		 * @var BladeTemplate|null Instance of BladeTemplate
		 */
		private static ?BladeTemplate $instance = null;
		
		/**
		 * Returns the provider's metadata for service discovery
		 * @return array Associative array containing provider metadata
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
		 * @return array Default configuration array
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
				return $metadata['provider'] === 'blade';
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
		 * @param array $dependencies Resolved dependencies (unused in this case)
		 * @param array $metadata Metadata as passed by Discover
		 * @param MethodContext|null $methodContext
		 * @return object Configured BladeTemplate instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContext $methodContext = null): object {
			// Return cached instance
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			// Get default configuration values
			$defaults = $this->getDefaults();
			
			// Get user-provided configuration (from config/blade.php or similar)
			$configuration = $this->getConfig();
			
			// Create BladeTemplate with merged configuration
			// User config takes precedence over defaults using null coalescing
			$instance = new BladeTemplate([
				'template_dir'  => $configuration['template_dir']  ?? $defaults['template_dir'],
				'cache_dir'     => $configuration['cache_dir']     ?? $defaults['cache_dir'],
				'caching'       => $configuration['caching']       ?? $defaults['caching'],
				'directives'    => $configuration['directives']    ?? $defaults['directives'],
				'if_directives' => $configuration['if_directives'] ?? $defaults['if_directives'],
				'paths'         => $configuration['paths']         ?? $defaults['paths'],
				'globals'       => $configuration['globals']       ?? $defaults['globals'],
			]);
			
			// Cache and return instance
			return self::$instance = $instance;
		}
	}