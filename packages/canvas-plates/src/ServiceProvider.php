<?php
	
	namespace Quellabs\Canvas\Plates;
	
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Contracts\Context\MethodContext;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	
	/**
	 * Plates Template Engine Service Provider for Canvas Framework
	 */
	class ServiceProvider extends \Quellabs\DependencyInjection\Provider\ServiceProvider {
		
		/**
		 * @var PlatesTemplate|null Cached singleton instance
		 */
		private static ?PlatesTemplate $instance = null;
		
		/**
		 * Returns the provider's metadata for service discovery
		 * @return array Associative array containing provider metadata
		 */
		public static function getMetadata(): array {
			return [
				'provider'     => 'plates',
				'type'         => 'template_engine',
				'capabilities' => ['inheritance', 'sections', 'functions', 'folders', 'native-php'],
				'extensions'   => ['.php'],
				'version'      => '1.0.0',
			];
		}
		
		/**
		 * Returns the default configuration settings for Plates
		 * @return array Default configuration array
		 */
		public static function getDefaults(): array {
			$projectRoot = ComposerUtils::getProjectRoot();
			
			return [
				// Directory where Plates template files are stored
				'template_dir' => $projectRoot . '/templates/',
				
				// File extension used for template files (Plates default is 'php')
				'extension'    => 'php',
				
				// Custom functions to register (name => callable)
				// Accessible in templates as: {$this->functionName(...)}
				'functions'    => [],
				
				// Additional template directories with optional namespaces (Plates "folders")
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
				return $metadata['provider'] === 'plates';
			}
			
			// Priority 2: Check app.php configuration
			if ($this->hasConfigValue('template_engine')) {
				return $this->getConfigValue('template_engine') === 'plates';
			}
			
			return true;
		}
		
		/**
		 * Creates and configures a new Plates template engine instance
		 * @param string $className The requested interface (TemplateEngineInterface)
		 * @param array $dependencies Resolved dependencies (unused)
		 * @param array $metadata Metadata as passed by Discover
		 * @param MethodContext|null $methodContext
		 * @return object Configured PlatesTemplate instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContext $methodContext = null): object {
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			$defaults      = $this->getDefaults();
			$configuration = $this->getConfig();
			
			self::$instance = new PlatesTemplate([
				'template_dir' => $configuration['template_dir'] ?? $defaults['template_dir'],
				'extension'    => $configuration['extension']    ?? $defaults['extension'],
				'functions'    => $configuration['functions']    ?? $defaults['functions'],
				'paths'        => $configuration['paths']        ?? $defaults['paths'],
				'globals'      => $configuration['globals']      ?? $defaults['globals'],
			]);
			
			return self::$instance;
		}
	}