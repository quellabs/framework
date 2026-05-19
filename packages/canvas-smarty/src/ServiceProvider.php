<?php
	
	namespace Quellabs\Canvas\Smarty;
	
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	
	/**
	 * Smarty Template Engine Service Provider for Canvas Framework
	 *
	 * @phpstan-type SmartyConfig array{
	 *     template_dir: string,
	 *     compile_dir: string,
	 *     cache_dir: string,
	 *     debugging: bool,
	 *     caching: int,
	 *     clear_compiled: bool,
	 *     cache_lifetime: int|null,
	 *     security: bool|null
	 * }
	 */
	class ServiceProvider extends \Quellabs\DependencyInjection\Provider\ServiceProvider {
		
		/**
		 * @var SmartyTemplate|null Instance of SmartyTemplate
		 */
		private static ?SmartyTemplate $instance = null;
		
		/**
		 * Returns the provider's metadata for service discovery
		 * @return array<string, mixed> Associative array containing provider metadata
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
		 * @return SmartyConfig
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
				
				// Smarty caching mode: 0 = off, 1 = CACHING_LIFETIME_CURRENT, 2 = CACHING_LIFETIME_SAVED
				'caching'        => 0,
				
				// Clear the compiled directory on cache flush
				'clear_compiled' => true,
				
				// Cache lifetime in seconds (null = use Smarty default)
				'cache_lifetime' => null,
				
				// Enable Smarty security policy (null = disabled)
				'security'       => null,
			];
		}
		
		/**
		 * Merges user-provided configuration over the defaults
		 * @return SmartyConfig
		 */
		public function mergeConfig(): array {
			$defaults = self::getDefaults();
			$configuration = $this->getConfig();
			
			return [
				'template_dir'   => $this->normalizeString($configuration['template_dir'] ?? null, $defaults['template_dir']),
				'compile_dir'    => $this->normalizeString($configuration['compile_dir'] ?? null, $defaults['compile_dir']),
				'cache_dir'      => $this->normalizeString($configuration['cache_dir'] ?? null, $defaults['cache_dir']),
				'debugging'      => $this->normalizeBool($configuration['debugging'] ?? null, $defaults['debugging']),
				'caching'        => $this->normalizeInt($configuration['caching'] ?? null, $defaults['caching']),
				'clear_compiled' => $this->normalizeBool($configuration['clear_compiled'] ?? null, $defaults['clear_compiled']),
				'cache_lifetime' => is_int($configuration['cache_lifetime'] ?? null) ? $configuration['cache_lifetime'] : $defaults['cache_lifetime'],
				'security'       => is_bool($configuration['security'] ?? null) ? $configuration['security'] : $defaults['security'],
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
				return $metadata['provider'] === 'smarty';
			}
			
			// Priority 2: Check app.php configuration
			if ($this->hasConfigValue('template_engine')) {
				return $this->getConfigValue('template_engine') === 'smarty';
			}
			
			// Return true to indicate this provider is available
			// The discovery system should handle "first encountered" logic
			return true;
		}
		
		/**
		 * Creates and configures a new Smarty template engine instance
		 * @param string $className The requested interface (TemplateEngineInterface)
		 * @param array<string, mixed> $dependencies Resolved dependencies (unused in this case)
		 * @param array<string, mixed> $metadata Metadata as passed by Discover
		 * @param MethodContextInterface|null $methodContext
		 * @return object Configured SmartyTemplate instance
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			// Return cached instance
			if (self::$instance !== null) {
				return self::$instance;
			}
			
			// Create and return SmartyTemplate with merged configuration
			$instance = new SmartyTemplate($this->mergeConfig());
			
			// Add to cache and return
			return self::$instance = $instance;
		}
	}