<?php
	
	namespace Quellabs\Canvas\Smarty;
	
	use Quellabs\Contracts\Templates\TemplateRenderException;
	use Quellabs\SignalHub\HasSignals;
	use Quellabs\SignalHub\Signal;
	use Quellabs\SignalHub\SignalHubLocator;
	use Smarty\Exception;
	use Smarty\Smarty;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	
	class SmartyTemplate implements TemplateEngineInterface {
		
		use HasSignals;
		
		/**
		 * @var Smarty|null Smarty instance
		 */
		private ?Smarty $smarty;
		
		/**
		 * @var array Configuration data provided by ServiceProvider
		 */
		private array $config;
		
		/**
		 * @var Signal Signal for performance monitoring
		 */
		private Signal $templateSignal;
		
		/**
		 * SmartyTemplate constructor
		 * @param array $configuration
		 */
		public function __construct(array $configuration) {
			// Store the configuration
			$this->config = $configuration;
			
			// Grab signalhub and create signal
			$this->setSignalHub(SignalHubLocator::getInstance());
			$this->templateSignal = $this->createSignal(['array'], 'debug.template.query');
			
			// Create Smarty instance
			$this->smarty = new Smarty();
			$this->smarty->setTemplateDir($configuration['template_dir']);
			$this->smarty->setCompileDir($configuration['compile_dir']);
			$this->smarty->setCacheDir($configuration['cache_dir']);
			$this->smarty->setDebugging($configuration['debugging']);
			$this->smarty->setCaching($configuration['caching']);
			
			// Set cache lifetime if specified
			// Only configure cache lifetime if explicitly provided in config
			if (isset($this->config['cache_lifetime'])) {
				$this->smarty->cache_lifetime = $this->config['cache_lifetime'];
			}
			
			// Enable security if specified
			// Optionally enable Smarty's security policy to restrict template operations
			if (isset($this->config['security']) && $this->config['security']) {
				try {
					$this->smarty->enableSecurity();
				} catch (\Exception $e) {
					error_log("SmartyTemplateProvider: unable to set Smarty security ({$e->getMessage()}");
				}
			}
		}
		
		/**
		 * Renders a template using the Smarty template engine
		 * @param string $template The template file name/path to render
		 * @param array $data Associative array of variables to pass to the template
		 * @return string The rendered template content as a string
		 * @throws TemplateRenderException If template rendering fails for any reason
		 */
		public function render(string $template, array $data = []): string {
			return $this->renderTemplate($template, $data, false);
		}
		
		/**
		 * Renders a template string with the provided data
		 * @param string $templateString The template content as a string
		 * @param array $data Associative array of variables to pass to the template
		 * @return string The rendered template content
		 * @throws TemplateRenderException If template rendering fails for any reason
		 */
		public function renderString(string $templateString, array $data = []): string {
			return $this->renderTemplate($templateString, $data, true);
		}
		
		/**
		 * Adds a global variable that will be available in all templates
		 * @param string $key The variable name to use in templates
		 * @param mixed $value The value to assign (can be any type: string, array, object, etc.)
		 * @return void
		 */
		public function addGlobal(string $key, mixed $value): void {
			// Assign the variable globally to the Smarty instance
			// This makes the variable available in all subsequent template renders
			// until the engine instance is reset or the variable is overwritten
			$this->smarty->assign($key, $value);
		}
		
		/**
		 * Checks if a template file exists and is accessible
		 * @param string $template The template file name/path to check for existence
		 * @return bool True if the template exists and is accessible, false otherwise
		 */
		public function exists(string $template): bool {
			try {
				// Use Smarty's built-in method to check if the template file exists
				// This method considers the configured template directory and search paths
				return $this->smarty->templateExists($template);
			} catch (\Exception $e) {
				// If any exception occurs during the check (e.g., permission issues,
				// invalid paths, or Smarty configuration problems), treat it as "not found"
				// This provides a fail-safe behavior rather than propagating exceptions
				return false;
			}
		}
		
		/**
		 * Clears the Smarty template cache and optionally compiled templates
		 * @return void
		 * @throws \Exception If cache clearing fails for any reason
		 */
		public function clearCache(): void {
			try {
				// Clear all cache
				// This removes all cached template output, forcing templates to be
				// re-rendered and re-cached on the next request
				$this->smarty->clearAllCache();
				
				// Also clear compiled templates if needed
				// Check configuration to see if compiled templates should also be cleared
				if (isset($this->config['clear_compiled']) && $this->config['clear_compiled']) {
					// Clear compiled PHP templates that Smarty generates from template files
					// This is useful when template syntax or structure has changed
					$this->smarty->clearCompiledTemplate();
				}
			} catch (\Exception $e) {
				// If cache clearing fails, wrap the exception with more context
				// This could happen due to file permission issues or disk space problems
				throw new \Exception("Failed to clear cache: " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Additional helper methods for Smarty-specific functionality
		 */
		
		/**
		 * Register a custom function with Smarty
		 * @param string $name The function name to use in templates
		 * @param callable $callback Function that handles the custom functionality
		 * @return void
		 */
		public function registerFunction(string $name, callable $callback): void {
			try {
				// Register the callback as a 'function' type plugin in Smarty
				// This allows the function to be called directly in templates
				$this->smarty->registerPlugin('function', $name, $callback);
			} catch (\Exception $e) {
				error_log("SmartyTemplateProvider: unable to register function {$name} ({$e->getMessage()}");
			}
		}
		
		/**
		 * Register a custom modifier with Smarty
		 * @param string $name The modifier name to use in templates
		 * @param callable $callback Function that transforms the input value
		 * @return void
		 */
		public function registerModifier(string $name, callable $callback): void {
			try {
				// Register the callback as a 'modifier' type plugin in Smarty
				// This allows the modifier to be used with the pipe operator on variables
				$this->smarty->registerPlugin('modifier', $name, $callback);
			} catch (\Exception $e) {
				error_log("SmartyTemplateProvider: unable to register modifier {$name} ({$e->getMessage()}");
			}
		}
		
		/**
		 * Register a custom block with Smarty
		 * @param string $name The block name to use in templates
		 * @param callable $callback Function that processes the block content
		 * @return void
		 */
		public function registerBlock(string $name, callable $callback): void {
			try {
				// Register the callback as a 'block' type plugin in Smarty
				// This allows the function to wrap and process template content
				$this->smarty->registerPlugin('block', $name, $callback);
			} catch (\Exception $e) {
				error_log("SmartyTemplateProvider: unable to register block {$name} ({$e->getMessage()}");
			}
		}
		
		/**
		 * Unlike clearCache() which clears all cached templates, this method
		 * removes the cache only for the specified template file. Useful when
		 * you know exactly which template needs to be refreshed.
		 * @param string $template The specific template file to clear from cache
		 * @return void
		 */
		public function clearTemplateCache(string $template): void {
			// Clear cache only for the specified template
			// This is more efficient than clearing all cache when only one template changed
			try {
				$this->smarty->clearCache($template);
			} catch (Exception $e) {
				error_log("SmartyTemplateProvider: unable to clear template cache: {$e->getMessage()}");
			}
		}
		
		/**
		 * Check if a specific template is currently cached
		 * @param string $template The template file to check for cache status
		 * @return bool True if the template is cached, false if it needs to be rendered
		 */
		public function isCached(string $template): bool {
			try {
				// Check if Smarty has a cached version of this template available
				// Returns true if cached and valid, false if it needs rendering
				return $this->smarty->isCached($template);
			} catch (\Exception $e) {
				return false;
			}
		}
		
		/**
		 * Internal method to handle both file and string template rendering
		 * @param string $template The template file name/path or template string content
		 * @param array $data Associative array of variables to pass to the template
		 * @param bool $isString Whether the template parameter is a string (true) or file path (false)
		 * @return string The rendered template content
		 * @throws TemplateRenderException
		 */
		private function renderTemplate(string $template, array $data, bool $isString): string {
			try {
				// Mark start for performance monitoring
				$start = microtime(true);
				
				// Create a data object with local scope that inherits from the Smarty instance
				// This allows access to global variables while keeping local variables isolated
				// Local variables will override global ones with the same name
				$localData = $this->smarty->createData($this->smarty);
				
				// Assign variables to the local data object scope
				// These variables will only be available for this specific render call
				// and will override any global variables with the same name
				foreach ($data as $key => $value) {
					$localData->assign($key, $value);
				}
				
				// Determine the template source based on type
				$templateSource = $isString ? 'string:' . $template : $template;
				
				// Fetch/render the template with local data scope
				$result = $this->smarty->fetch($templateSource, $localData);
				
				// Send event
				$this->templateSignal->emit([
					'template'          => $template,
					'bound_parameters'  => $data,
					'is_string'         => $isString,
					'execution_time_ms' => microtime(true) - $start,
				]);
				
				// Return the result
				return $result;

			} catch (\Exception $e) {
				// Create the appropriate error message based on the template type
				if ($isString) {
					$snippet = strlen($template) > 50 ? substr($template, 0, 50) . '...' : $template;
					$errorContext = "template string '{$snippet}'";
				} else {
					$errorContext = "template '{$template}'";
				}
				
				throw new TemplateRenderException("Failed to render {$errorContext}: ", $e->getMessage(), $e);
			}
		}
	}