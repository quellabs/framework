<?php
	
	namespace Quellabs\Canvas\Blade;
	
	use Illuminate\View\Factory;
	use Jenssegers\Blade\Blade;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Quellabs\Contracts\Templates\TemplateRenderException;
	use Quellabs\Support\ComposerUtils;
	
	class BladeTemplate implements TemplateEngineInterface {
		
		/**
		 * @var Blade The jenssegers/blade wrapper instance
		 */
		private Blade $blade;
		
		/**
		 * @var Factory The underlying Illuminate View Factory
		 */
		private Factory $factory;
		
		/**
		 * @var array Configuration data provided by ServiceProvider
		 */
		private array $config;
		
		/**
		 * @var array Global variables available to all templates
		 */
		private array $globals = [];
		
		/**
		 * BladeTemplate constructor
		 * @param array $configuration
		 */
		public function __construct(array $configuration) {
			$this->config = $configuration;
			
			$viewPaths = (array) ($configuration['template_dir'] ?? ComposerUtils::getProjectRoot() . DIRECTORY_SEPARATOR . 'templates');
			$cachePath = $configuration['caching'] ? ($configuration['cache_dir'] ?? null) : null;
			
			$this->blade   = new Blade($viewPaths, $cachePath);
			$this->factory = $this->blade->view();
			
			// Add additional view paths if configured
			if (!empty($configuration['paths'])) {
				foreach ((array) $configuration['paths'] as $namespace => $path) {
					if (is_string($namespace)) {
						$this->addPath($path, $namespace);
					} else {
						$this->addPath($path);
					}
				}
			}
			
			// Register custom directives if configured
			if (!empty($configuration['directives']) && is_array($configuration['directives'])) {
				foreach ($configuration['directives'] as $name => $callback) {
					if (is_callable($callback)) {
						$this->registerDirective($name, $callback);
					}
				}
			}
			
			// Register custom if-directives if configured
			if (!empty($configuration['if_directives']) && is_array($configuration['if_directives'])) {
				foreach ($configuration['if_directives'] as $name => $callback) {
					if (is_callable($callback)) {
						$this->registerIfDirective($name, $callback);
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
		 * Renders a template using the Blade template engine
		 * @param string $template The template name (dot-notation, e.g. 'emails.welcome')
		 * @param array $data Associative array of variables to pass to the template
		 * @return string The rendered template content as a string
		 * @throws TemplateRenderException If template rendering fails for any reason
		 */
		public function render(string $template, array $data = []): string {
			try {
				$mergedData = array_merge($this->globals, $data);
				return $this->factory->make($template, $mergedData)->render();
			} catch (\Throwable $e) {
				throw new TemplateRenderException("Failed to render template '{$template}': " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Renders a Blade template string directly
		 *
		 * Blade has no native in-memory string renderer, so we compile via a
		 * temporary file that is cleaned up immediately after rendering.
		 *
		 * @param string $templateString The template content as a string
		 * @param array $data Associative array of variables to pass to the template
		 * @return string The rendered template content
		 * @throws TemplateRenderException If template rendering fails for any reason
		 */
		public function renderString(string $templateString, array $data = []): string {
			// Write to a temp .blade.php file in the system temp dir
			$tmpDir  = sys_get_temp_dir();
			$tmpName = 'blade_str_' . md5($templateString) . '_' . getmypid();
			$tmpFile = $tmpDir . DIRECTORY_SEPARATOR . $tmpName . '.blade.php';
			
			try {
				if (file_put_contents($tmpFile, $templateString) === false) {
					throw new \RuntimeException("Unable to write temporary template file.");
				}
				
				// Temporarily register the temp directory as a view path
				$this->blade->addLocation($tmpDir);
				
				$mergedData = array_merge($this->globals, $data);
				$result = $this->factory->make($tmpName, $mergedData)->render();
				
				return $result;
			} catch (\Throwable $e) {
				$snippet = strlen($templateString) > 50 ? substr($templateString, 0, 50) . '...' : $templateString;
				throw new TemplateRenderException("Failed to render template string '{$snippet}': " . $e->getMessage(), 0, $e);
			} finally {
				// Always clean up the temp file
				if (file_exists($tmpFile)) {
					@unlink($tmpFile);
				}
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
			$this->factory->share($key, $value);
		}
		
		/**
		 * Checks if a template file exists and is accessible
		 * @param string $template Template name in dot-notation (e.g. 'emails.welcome')
		 * @return bool True if the template exists and is accessible
		 */
		public function exists(string $template): bool {
			try {
				return $this->factory->exists($template);
			} catch (\Exception $e) {
				return false;
			}
		}
		
		/**
		 * Clears the entire Blade compiled template cache
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
				error_log("BladeTemplate: failed to clear cache: " . $e->getMessage());
			}
		}
		
		/**
		 * Registers a custom Blade directive
		 *
		 * Blade directives are the equivalent of Twig functions/filters.
		 * The callback receives the expression string and must return valid PHP.
		 * Example: $callback = fn($expr) => "<?php echo strtoupper($expr); ?>"
		 *
		 * @param string $name The directive name (without @)
		 * @param callable $callback Receives the raw expression string, returns PHP code
		 * @return void
		 */
		public function registerDirective(string $name, callable $callback): void {
			try {
				$this->blade->compiler()->directive($name, $callback);
			} catch (\Exception $e) {
				error_log("BladeTemplate: unable to register directive '{$name}' ({$e->getMessage()})");
			}
		}
		
		/**
		 * Registers a custom Blade @if-directive pair (@name / @elsename / @endname)
		 *
		 * @param string $name The condition name (without @)
		 * @param callable $callback Receives the expression arguments, returns bool
		 * @return void
		 */
		public function registerIfDirective(string $name, callable $callback): void {
			try {
				$this->blade->compiler()->if($name, $callback);
			} catch (\Exception $e) {
				error_log("BladeTemplate: unable to register if-directive '{$name}' ({$e->getMessage()})");
			}
		}
		
		/**
		 * Add a template directory to the view finder
		 * @param string $path The directory path to add
		 * @param string|null $namespace Optional namespace (e.g. 'admin')
		 * @return void
		 */
		public function addPath(string $path, ?string $namespace = null): void {
			try {
				if ($namespace) {
					$this->factory->getFinder()->addNamespace($namespace, $path);
				} else {
					$this->blade->addLocation($path);
				}
			} catch (\Exception $e) {
				error_log("BladeTemplate: unable to add path '{$path}' ({$e->getMessage()})");
			}
		}
		
		/**
		 * Get the underlying Blade instance
		 * @return Blade
		 */
		public function getBlade(): Blade {
			return $this->blade;
		}
		
		/**
		 * Get the underlying Illuminate View Factory
		 * @return Factory
		 */
		public function getFactory(): Factory {
			return $this->factory;
		}
		
		/**
		 * Check if a specific template is currently compiled/cached
		 * @param string $template Template name in dot-notation
		 * @return bool True if a compiled file exists for this template
		 */
		public function isCached(string $template): bool {
			try {
				$compiler  = $this->blade->compiler();
				$viewPath  = $this->factory->getFinder()->find($template);
				$compiled  = $compiler->getCompiledPath($viewPath);
				
				return file_exists($compiled);
			} catch (\Exception $e) {
				return false;
			}
		}
		
		/**
		 * Clear the compiled cache for a specific template
		 * @param string $template Template name in dot-notation
		 * @return void
		 */
		public function clearTemplateCache(string $template): void {
			try {
				$compiler = $this->blade->compiler();
				$viewPath = $this->factory->getFinder()->find($template);
				$compiled = $compiler->getCompiledPath($viewPath);
				
				if (file_exists($compiled)) {
					unlink($compiled);
				}
			} catch (\Exception $e) {
				error_log("BladeTemplate: unable to clear cache for template '{$template}' ({$e->getMessage()})");
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