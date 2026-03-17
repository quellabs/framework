<?php
	
	namespace Quellabs\Canvas\Latte;
	
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	
	/**
	 * Latte Template Engine Service Provider for Canvas Framework
	 */
	class ServiceProvider extends \Quellabs\DependencyInjection\Provider\ServiceProvider {
		
		/**
		 * @var LatteTemplate|null Cached singleton instance
		 */
		private static ?LatteTemplate $instance = null;
		
		/**
		 * Returns the provider's metadata for service discovery
		 * @return array Associative array containing provider metadata
		 */
		public static function getMetadata(): array {
			return [
				'provider'     => 'latte',
				'type'         => 'template_engine',
				'capabilities' => ['inheritance', 'blocks', 'filters', 'functions', 'extensions', 'sandboxing'],
				'extensions'   => ['.latte'],
				'version'      => '1.0.0',
			];
		}
		
		/**
		 * Returns the default configuration settings for Latte
		 * @return array Default configuration array
		 */
		public static function getDefaults(): array {
			$projectRoot = ComposerUtils::getProjectRoot();
			
			return [
				// Directory where Latte template files (.latte) are stored
				'template_dir' => $projectRoot . '/templates/',
				
				// Directory where Latte stores compiled PHP templates
				'cache_dir'    => $projectRoot . '/storage/cache/latte/',
				
				// Enable/disable template caching
				'caching'      => true,
				
				// Custom Latte filters to register (name => callable)
				// Used in templates as: {$value|filterName}
				'filters'      => [],
				
				// Custom Latte functions to register (name => callable)
				// Used in templates as: {functionName(...)}
				'functions'    => [],
				
				// Latte\Extension instances to register
				'extensions'   => [],
				
				// Additional template directories with optional namespaces
				// Keyed entries use the key as namespace: ['admin' => '/path/to/admin/templates']
				'paths'        => [],
				
				// Global variables available in all templates
				'globals'      => [],
			];
		}
		
		/**
		 * Determines if this provider can handle the requested service
		 * @param string $className The interface/class name being requested
		 * @param array $metadata Additional metadata from the service request
		 * @return bool True if this provider can handle the request
		 */
		public function supports(string $className, array $metadata): bool {
			if ($className !== TemplateEngineInterface::class) {
				return false;
			}
			
			// Priority 1: Explicit provider request via context/metadata
			if (!empty($metadata['provider'])) {
				return $metadata['provider'] === 'latte';
			}
			
			// Priority 2: Check app.php configuration
			if ($this->hasConfigValue('template_engine')) {
				return $this->getConfigValue('template_engine') === 'latte';
			}
			
			return true;
		}
		
		/**
		 * Creates and configures a new Latte template engine instance
		 * @param string $className The requested interface (TemplateEngineInterface)
		 * @param array $dependencies Resolved dependencies (unused)
		 * @param array $metadata Metadata as passed by Discover
		 * @param MethodContextInterface|null $methodContext
		 * @return object Configured LatteTemplate instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			$defaults      = $this->getDefaults();
			$configuration = $this->getConfig();
			
			self::$instance = new LatteTemplate([
				'template_dir' => $configuration['template_dir'] ?? $defaults['template_dir'],
				'cache_dir'    => $configuration['cache_dir']    ?? $defaults['cache_dir'],
				'caching'      => $configuration['caching']      ?? $defaults['caching'],
				'filters'      => $configuration['filters']      ?? $defaults['filters'],
				'functions'    => $configuration['functions']    ?? $defaults['functions'],
				'extensions'   => $configuration['extensions']   ?? $defaults['extensions'],
				'paths'        => $configuration['paths']        ?? $defaults['paths'],
				'globals'      => $configuration['globals']      ?? $defaults['globals'],
			]);
			
			return self::$instance;
		}
	}