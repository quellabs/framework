<?php
	
	namespace Quellabs\Canvas\Latte;
	
	use Latte\Engine;
	use Latte\Loaders\FileLoader;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Quellabs\Contracts\Templates\TemplateRenderException;
	use Quellabs\Support\ComposerUtils;
	
	class LatteTemplate implements TemplateEngineInterface {
		
		/**
		 * @var Engine The Latte engine instance
		 */
		private Engine $engine;
		
		/**
		 * @var array<string, string> Registered template paths, keyed by namespace (or '' for default)
		 */
		private array $paths = [];
		
		/**
		 * @var array Configuration data provided by ServiceProvider
		 */
		private array $config;
		
		/**
		 * @var array Global variables available to all templates
		 */
		private array $globals = [];
		
		/**
		 * LatteTemplate constructor
		 * @param array $configuration
		 */
		public function __construct(array $configuration) {
			$this->config = $configuration;
			
			$templateDir = $configuration['template_dir'] ?? ComposerUtils::getProjectRoot() . DIRECTORY_SEPARATOR . 'templates';
			$cachePath   = ($configuration['caching'] ?? true) ? ($configuration['cache_dir'] ?? null) : null;
			
			// Store the primary path under the default (empty) namespace
			$this->paths[''] = rtrim($templateDir, '/\\');
			
			// Build the engine
			$this->engine = new Engine();

			// FileLoader without a base path — resolveTemplatePath() always returns
			// absolute paths, so we don't want FileLoader prepending anything.
			$this->engine->setLoader(new FileLoader());

			// null disables the cache (Latte accepts null here)
			$this->engine->setCacheDirectory($cachePath);
			
			// Register additional namespaced/plain paths if configured
			if (!empty($configuration['paths'])) {
				foreach ((array) $configuration['paths'] as $namespace => $path) {
					$this->addPath($path, is_string($namespace) ? $namespace : null);
				}
			}
			
			// Register custom filters if configured
			if (!empty($configuration['filters']) && is_array($configuration['filters'])) {
				foreach ($configuration['filters'] as $name => $callback) {
					if (is_callable($callback)) {
						$this->registerFilter($name, $callback);
					}
				}
			}
			
			// Register custom functions if configured
			if (!empty($configuration['functions']) && is_array($configuration['functions'])) {
				foreach ($configuration['functions'] as $name => $callback) {
					if (is_callable($callback)) {
						$this->registerFunction($name, $callback);
					}
				}
			}
			
			// Register Latte extensions if configured
			if (!empty($configuration['extensions']) && is_array($configuration['extensions'])) {
				foreach ($configuration['extensions'] as $extension) {
					if ($extension instanceof \Latte\Extension) {
						$this->engine->addExtension($extension);
					}
				}
			}
			
			// Add global variables if configured
			if (!empty($configuration['globals']) && is_array($configuration['globals'])) {
				foreach ($configuration['globals'] as $key => $value) {
					$this->addGlobal($key, $value);
				}
			}
		}
		
		/**
		 * Renders a template file using Latte
		 * @param string $template Template name, optionally prefixed with a namespace (e.g. 'admin:users/list')
		 * @param array $data Associative array of variables to pass to the template
		 * @return string The rendered template content as a string
		 * @throws TemplateRenderException If template rendering fails for any reason
		 */
		public function render(string $template, array $data = []): string {
			try {
				$mergedData  = array_merge($this->globals, $data);
				$resolvedPath = $this->resolveTemplatePath($template);
				return $this->engine->renderToString($resolvedPath, $mergedData);
			} catch (\Throwable $e) {
				throw new TemplateRenderException($template, "Failed to render template: " . $e->getMessage(), $e);
			}
		}
		
		/**
		 * Render a Latte template string
		 * @param string $templateString The template content as a string
		 * @param array $data Associative array of variables to pass to the template
		 * @return string The rendered template content
		 * @throws TemplateRenderException If template rendering fails for any reason
		 */
		public function renderString(string $templateString, array $data = []): string {
			try {
				$mergedData = array_merge($this->globals, $data);
				
				// Clone the engine so we can swap the loader without affecting
				// the file-based engine used by render()
				$stringEngine = clone $this->engine;
				$stringEngine->setLoader(new \Latte\Loaders\StringLoader());
				
				return $stringEngine->renderToString($templateString, $mergedData);
			} catch (\Throwable $e) {
				$snippet = strlen($templateString) > 50 ? substr($templateString, 0, 50) . '...' : $templateString;
				throw new TemplateRenderException($snippet, "Failed to render template string: " . $e->getMessage(), $e);
			}
		}
		
		/**
		 * Adds a global variable available in all templates
		 * @param string $key The variable name to use in templates
		 * @param mixed $value The value to assign
		 * @return void
		 */
		public function addGlobal(string $key, mixed $value): void {
			$this->globals[$key] = $value;
		}
		
		/**
		 * Checks if a template file exists and is accessible
		 * @param string $template Template name, optionally namespaced (e.g. 'admin:users/list')
		 * @return bool True if the template exists and is accessible
		 */
		public function exists(string $template): bool {
			try {
				return file_exists($this->resolveTemplatePath($template));
			} catch (\Exception $e) {
				return false;
			}
		}
		
		/**
		 * Clears all compiled Latte templates from the cache directory
		 * @return void
		 */
		public function clearCache(): void {
			$cacheDir = $this->config['cache_dir'] ?? null;
			
			if (!$cacheDir || !is_dir($cacheDir)) {
				return;
			}
			
			try {
				$this->recursiveDelete($cacheDir);
			} catch (\Exception $e) {
				error_log("LatteTemplate: failed to clear cache: " . $e->getMessage());
			}
		}
		
		/**
		 * Registers a custom Latte filter
		 * @param string $name The filter name
		 * @param callable $callback Receives the value (and optional args), returns transformed value
		 * @return void
		 */
		public function registerFilter(string $name, callable $callback): void {
			try {
				$this->engine->addFilter($name, $callback);
			} catch (\Exception $e) {
				error_log("LatteTemplate: unable to register filter '{$name}' ({$e->getMessage()})");
			}
		}
		
		/**
		 * Registers a custom Latte function
		 * @param string $name The function name
		 * @param callable $callback
		 * @return void
		 */
		public function registerFunction(string $name, callable $callback): void {
			try {
				$this->engine->addFunction($name, $callback);
			} catch (\Exception $e) {
				error_log("LatteTemplate: unable to register function '{$name}' ({$e->getMessage()})");
			}
		}
		
		/**
		 * Add a template directory, with an optional namespace prefix
		 * @param string $path The directory path to add
		 * @param string|null $namespace Optional namespace prefix
		 * @return void
		 */
		public function addPath(string $path, ?string $namespace = null): void {
			try {
				$key = $namespace ?? '';
				$this->paths[$key] = rtrim($path, '/\\');
			} catch (\Exception $e) {
				error_log("LatteTemplate: unable to add path '{$path}' ({$e->getMessage()})");
			}
		}
		
		/**
		 * Get the underlying Latte Engine instance
		 * @return Engine
		 */
		public function getEngine(): Engine {
			return $this->engine;
		}
		
		/**
		 * Check if a specific template is currently compiled/cached
		 * @param string $template Template name, optionally namespaced
		 * @return bool True if a compiled file exists for this template
		 */
		public function isCached(string $template): bool {
			try {
				$sourcePath  = $this->resolveTemplatePath($template);
				$compiledPath = $this->resolveCompiledPath($sourcePath);
				return file_exists($compiledPath);
			} catch (\Exception $e) {
				return false;
			}
		}
		
		/**
		 * Clear the compiled cache for a specific template
		 * @param string $template Template name, optionally namespaced
		 * @return void
		 */
		public function clearTemplateCache(string $template): void {
			try {
				$sourcePath   = $this->resolveTemplatePath($template);
				$compiledPath = $this->resolveCompiledPath($sourcePath);
				
				if (file_exists($compiledPath)) {
					unlink($compiledPath);
				}
			} catch (\Exception $e) {
				error_log("LatteTemplate: unable to clear cache for template '{$template}' ({$e->getMessage()})");
			}
		}
		
		/**
		 * Resolves a template name to its full filesystem path.
		 * @param string $template Template name, e.g. 'users/list' or 'admin:users/list'
		 * @return string Absolute path to the template file
		 * @throws \InvalidArgumentException If the namespace is unknown
		 */
		private function resolveTemplatePath(string $template): string {
			if (str_contains($template, ':')) {
				[$namespace, $relative] = explode(':', $template, 2);
				
				if (!isset($this->paths[$namespace])) {
					throw new \InvalidArgumentException("Unknown Latte template namespace '{$namespace}'.");
				}
				
				return $this->paths[$namespace] . DIRECTORY_SEPARATOR . ltrim($relative, '/\\') . '.latte';
			}
			
			return $this->paths[''] . DIRECTORY_SEPARATOR . ltrim($template, '/\\') . '.latte';
		}
		
		/**
		 * Derives the compiled cache file path for a given source path.
		 * Latte uses a predictable naming scheme: md5 of the source path + '.php'.
		 * @param string $sourcePath Absolute path to the source template
		 * @return string Absolute path to the compiled cache file
		 */
		private function resolveCompiledPath(string $sourcePath): string {
			$cacheDir = $this->config['cache_dir'] ?? '';
			return rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . md5($sourcePath) . '.php';
		}
		
		/**
		 * Recursively delete a directory and its contents
		 * @param string $dir The directory to delete
		 * @return void
		 */
		private function recursiveDelete(string $dir): void {
			// Nothing to do if the directory doesn't exist
			if (!is_dir($dir)) {
				return;
			}
			
			// List contents, excluding . and ..
			$files = array_diff(scandir($dir), ['.', '..']);
			
			foreach ($files as $file) {
				$path = $dir . DIRECTORY_SEPARATOR . $file;
				
				if (is_dir($path)) {
					$this->recursiveDelete($path); // Recurse into subdirectories
				} else {
					unlink($path); // Delete the file
				}
			}
			
			rmdir($dir); // Remove the now-empty directory
		}
	}