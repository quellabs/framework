<?php
	
	namespace Quellabs\Canvas\Twig;
	
	use Quellabs\Contracts\Templates\TemplateRenderException;
	use Quellabs\Support\ComposerUtils;
	use Twig\Environment;
	use Twig\Loader\FilesystemLoader;
	use Twig\Loader\ArrayLoader;
	use Twig\Cache\FilesystemCache;
	use Twig\Extension\DebugExtension;
	use Twig\TwigFunction;
	use Twig\TwigFilter;
	use Twig\Error\LoaderError;
	use Twig\Error\RuntimeError;
	use Twig\Error\SyntaxError;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	
	class TwigTemplate implements TemplateEngineInterface {
		
		/**
		 * @var Environment|null Twig Environment instance
		 */
		private ?Environment $twig;
		
		/**
		 * @var FilesystemLoader Twig filesystem loader
		 */
		private FilesystemLoader $loader;
		
		/**
		 * @var array Configuration data provided by ServiceProvider
		 */
		private array $config;
		
		/**
		 * @var array Global variables available to all templates
		 */
		private array $globals = [];
		
		/**
		 * @var string|null Cache directory path
		 */
		private ?string $cacheDir = null;
		
		/**
		 * @var array Twig environment options
		 */
		private array $twigOptions;
		
		/**
		 * TwigTemplate constructor
		 * @param array $configuration
		 */
		public function __construct(array $configuration) {
			// Store the configuration
			$this->config = $configuration;
			
			// Create filesystem loader
			$this->loader = new FilesystemLoader($configuration['template_dir'] ?? ComposerUtils::getProjectRoot() . DIRECTORY_SEPARATOR . 'templates');
			
			// Configure Twig environment options
			$options = [
				'debug'            => $configuration['debugging'] ?? false,
				'auto_reload'      => $configuration['auto_reload'] ?? true,
				'strict_variables' => $configuration['strict_variables'] ?? false,
				'charset'          => $configuration['charset'] ?? 'UTF-8',
				'autoescape'       => $configuration['autoescape'] ?? 'html',
			];
			
			// Set cache directory if caching is enabled
			if (isset($configuration['caching']) && $configuration['caching']) {
				$this->cacheDir = $configuration['cache_dir'];
				$options['cache'] = new FilesystemCache($this->cacheDir);
			} else {
				$options['cache'] = false;
			}
			
			// Store options for string template rendering
			$this->twigOptions = $options;
			
			// Create Twig environment
			$this->twig = new Environment($this->loader, $this->twigOptions);
			
			// Enable debug extension if debugging is enabled
			if ($this->twigOptions['debug']) {
				$this->twig->addExtension(new DebugExtension());
			}
			
			// Add any custom extensions if specified
			if (isset($this->config['extensions']) && is_array($this->config['extensions'])) {
				foreach ($this->config['extensions'] as $extension) {
					if (class_exists($extension)) {
						$this->twig->addExtension(new $extension());
					}
				}
			}
			
			// Add additional paths if configured
			if (isset($this->config['paths']) && is_array($this->config['paths'])) {
				foreach ($this->config['paths'] as $namespace => $path) {
					if (is_string($namespace)) {
						// Namespaced path
						$this->addPath($path, $namespace);
					} else {
						// Non-namespaced path
						$this->addPath($path);
					}
				}
			}
			
			// Register custom functions if configured
			if (isset($this->config['functions']) && is_array($this->config['functions'])) {
				foreach ($this->config['functions'] as $name => $callback) {
					if (is_callable($callback)) {
						$this->registerFunction($name, $callback);
					}
				}
			}
			
			// Register custom filters if configured
			if (isset($this->config['filters']) && is_array($this->config['filters'])) {
				foreach ($this->config['filters'] as $name => $callback) {
					if (is_callable($callback)) {
						$this->registerFilter($name, $callback);
					}
				}
			}
			
			// Add global variables if configured
			if (isset($this->config['globals']) && is_array($this->config['globals'])) {
				foreach ($this->config['globals'] as $key => $value) {
					$this->addGlobal($key, $value);
				}
			}
		}
		
		/**
		 * Renders a template using the Twig template engine
		 * @param string $template The template file name/path to render
		 * @param array $data Associative array of variables to pass to the template
		 * @return string The rendered template content as a string
		 * @throws \Exception If template rendering fails for any reason
		 */
		public function render(string $template, array $data = []): string {
			try {
				// Merge global variables with local data (local data takes precedence)
				$mergedData = array_merge($this->globals, $data);
				
				// Render the template
				return $this->twig->render($template, $mergedData);
			} catch (LoaderError | RuntimeError | SyntaxError $e) {
				throw new TemplateRenderException("Failed to render template '{$template}': " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Renders a template string with the provided data
		 * @param string $templateString The template content as a string
		 * @param array $data Associative array of variables to pass to the template
		 * @return string The rendered template content
		 * @throws \Exception If template rendering fails for any reason
		 */
		public function renderString(string $templateString, array $data = []): string {
			try {
				// Create a temporary ArrayLoader for the string template
				$arrayLoader = new ArrayLoader(['__string_template__' => $templateString]);
				
				// Create options without cache for string templates
				$stringOptions = $this->twigOptions;
				$stringOptions['cache'] = false; // Don't cache string templates
				
				// Create a new Twig environment with the array loader
				$stringTwig = new Environment($arrayLoader, $stringOptions);
				
				// Copy extensions from main environment
				foreach ($this->twig->getExtensions() as $extension) {
					$stringTwig->addExtension($extension);
				}
				
				// Copy global variables
				foreach ($this->twig->getGlobals() as $key => $value) {
					$stringTwig->addGlobal($key, $value);
				}
				
				// Merge global variables with local data (local data takes precedence)
				$mergedData = array_merge($this->globals, $data);
				
				// Render the template string
				return $stringTwig->render('__string_template__', $mergedData);
			} catch (LoaderError | RuntimeError | SyntaxError $e) {
				$snippet = strlen($templateString) > 50 ? substr($templateString, 0, 50) . '...' : $templateString;
				throw new TemplateRenderException("Failed to render template string '{$snippet}': " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Adds a global variable that will be available in all templates
		 * @param string $key The variable name to use in templates
		 * @param mixed $value The value to assign (can be any type: string, array, object, etc.)
		 * @return void
		 */
		public function addGlobal(string $key, mixed $value): void {
			// Store in our internal globals array
			$this->globals[$key] = $value;
			
			// Add to Twig environment as well
			$this->twig->addGlobal($key, $value);
		}
		
		/**
		 * Checks if a template file exists and is accessible
		 * @param string $template The template file name/path to check for existence
		 * @return bool True if the template exists and is accessible, false otherwise
		 */
		public function exists(string $template): bool {
			try {
				// Use Twig's loader to check if the template exists
				return $this->loader->exists($template);
			} catch (\Exception $e) {
				// If any exception occurs during the check, treat it as "not found"
				return false;
			}
		}
		
		/**
		 * Clears the Twig template cache
		 * @return void
		 */
		public function clearCache(): void {
			try {
				$cache = $this->twig->getCache();
				
				if ($cache instanceof FilesystemCache && $this->cacheDir) {
					// Clear the cache directory
					$this->recursiveDelete($this->cacheDir);
				}
			} catch (\Exception $e) {
				error_log("TwigTemplate: failed to clear cache: " . $e->getMessage());
			}
		}
		
		/**
		 * Register a custom function with Twig
		 * @param string $name The function name to use in templates
		 * @param callable $callback Function that handles the custom functionality
		 * @return void
		 */
		public function registerFunction(string $name, callable $callback): void {
			try {
				$function = new TwigFunction($name, $callback);
				$this->twig->addFunction($function);
			} catch (\Exception $e) {
				error_log("TwigTemplate: unable to register function {$name} ({$e->getMessage()})");
			}
		}
		
		/**
		 * Register a custom filter with Twig
		 * @param string $name The filter name to use in templates
		 * @param callable $callback Function that transforms the input value
		 * @return void
		 */
		public function registerFilter(string $name, callable $callback): void {
			try {
				$filter = new TwigFilter($name, $callback);
				$this->twig->addFilter($filter);
			} catch (\Exception $e) {
				error_log("TwigTemplate: unable to register filter {$name} ({$e->getMessage()})");
			}
		}
		
		/**
		 * Add a template directory to the loader
		 * @param string $path The directory path to add
		 * @param string|null $namespace Optional namespace for the path
		 * @return void
		 */
		public function addPath(string $path, ?string $namespace = null): void {
			try {
				if ($namespace) {
					$this->loader->addPath($path, $namespace);
				} else {
					$this->loader->addPath($path);
				}
			} catch (\Exception $e) {
				error_log("TwigTemplate: unable to add path {$path} ({$e->getMessage()})");
			}
		}
		
		/**
		 * Get the underlying Twig Environment instance
		 * @return Environment
		 */
		public function getTwig(): Environment {
			return $this->twig;
		}
		
		/**
		 * Check if a specific template is currently cached
		 * @param string $template The template file to check for cache status
		 * @return bool True if the template is cached, false if it needs to be rendered
		 */
		public function isCached(string $template): bool {
			try {
				$cache = $this->twig->getCache();
				if (!$cache instanceof FilesystemCache) {
					return false;
				}
				
				// Get the cache key for this template
				$key = $cache->generateKey($template, $this->twig->getTemplateClass($template));
				
				// Check if the cached file exists and is not expired
				return $cache->getTimestamp($key) !== 0;
			} catch (\Exception $e) {
				return false;
			}
		}
		
		/**
		 * Clear cache for a specific template
		 * @param string $template The specific template file to clear from cache
		 * @return void
		 */
		public function clearTemplateCache(string $template): void {
			try {
				$cache = $this->twig->getCache();
				
				if ($cache instanceof FilesystemCache && $this->cacheDir) {
					$key = $cache->generateKey($template, $this->twig->getTemplateClass($template));
					$cacheFile = $this->cacheDir . '/' . $key . '.php';
					
					// Delete the cached file if it exists
					if (file_exists($cacheFile)) {
						unlink($cacheFile);
					}
				}
			} catch (\Exception $e) {
				error_log("TwigTemplate: unable to clear cache for template {$template} ({$e->getMessage()})");
			}
		}
		
		/**
		 * Recursively delete a directory and its contents
		 * @param string $dir The directory to delete
		 * @return void
		 */
		private function recursiveDelete(string $dir): void {
			if (!is_dir($dir)) {
				return;
			}
			
			$files = array_diff(scandir($dir), ['.', '..']);
			
			foreach ($files as $file) {
				$path = $dir . DIRECTORY_SEPARATOR . $file;
				
				if (is_dir($path)) {
					$this->recursiveDelete($path);
				} else {
					unlink($path);
				}
			}
			
			rmdir($dir);
		}
	}