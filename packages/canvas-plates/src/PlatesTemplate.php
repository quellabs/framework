<?php
	
	namespace Quellabs\Canvas\Plates;
	
	use League\Plates\Engine;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Quellabs\Contracts\Templates\TemplateRenderException;
	use Quellabs\Support\ComposerUtils;
	
	class PlatesTemplate implements TemplateEngineInterface {
		
		/**
		 * @var Engine The Plates engine instance
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
		 * PlatesTemplate constructor
		 * @param array $configuration
		 */
		public function __construct(array $configuration) {
			$this->config = $configuration;
			
			$templateDir = $configuration['template_dir'] ?? ComposerUtils::getProjectRoot() . DIRECTORY_SEPARATOR . 'templates';
			$extension   = $configuration['extension'] ?? 'php';
			
			// Store the primary path under the default (empty) namespace
			$this->paths[''] = rtrim($templateDir, '/\\');
			
			// Build the engine with the default directory and file extension
			$this->engine = new Engine($this->paths[''], $extension);
			
			// Register additional namespaced/plain paths if configured.
			// Plates calls these "folders"; each namespace maps to one folder.
			if (!empty($configuration['paths'])) {
				foreach ((array)$configuration['paths'] as $namespace => $path) {
					$this->addPath($path, is_string($namespace) ? $namespace : null);
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
			
			// Add global variables if configured
			if (!empty($configuration['globals']) && is_array($configuration['globals'])) {
				foreach ($configuration['globals'] as $key => $value) {
					$this->addGlobal($key, $value);
				}
			}
		}
		
		/**
		 * Renders a template file using Plates
		 * @param string $template Template name, optionally prefixed with a namespace (e.g. 'admin::users/list')
		 * @param array $data Associative array of variables to pass to the template
		 * @return string The rendered template content as a string
		 * @throws TemplateRenderException If template rendering fails for any reason
		 */
		public function render(string $template, array $data = []): string {
			try {
				$mergedData = array_merge($this->globals, $data);
				return $this->engine->render($template, $mergedData);
			} catch (\Throwable $e) {
				throw new TemplateRenderException($template, "Failed to render template: " . $e->getMessage(), $e);
			}
		}
		
		/**
		 * Render a Plates template string.
		 * Plates is a native-PHP engine and has no built-in string rendering support.
		 * This method writes the string to a temporary file, renders it, then cleans up.
		 * @param string $templateString The template content as a string
		 * @param array $data Associative array of variables to pass to the template
		 * @return string The rendered template content
		 * @throws TemplateRenderException If template rendering fails for any reason
		 */
		public function renderString(string $templateString, array $data = []): string {
			$tmpFile = null;
			
			try {
				$mergedData = array_merge($this->globals, $data);
				
				// Write the template string to a uniquely named temp file inside a
				// directory the engine can reach, then render it via a cloned engine
				// that points at the system temp dir.
				$tmpDir  = sys_get_temp_dir();
				$tmpName = 'plates_str_' . bin2hex(random_bytes(8));
				$tmpFile = $tmpDir . DIRECTORY_SEPARATOR . $tmpName . '.php';
				
				if (file_put_contents($tmpFile, $templateString) === false) {
					throw new \RuntimeException("Unable to write temporary template file.");
				}
				
				// Clone the engine and point it at the temp directory so the existing
				// folder registrations (namespaces) on $this->engine are not disturbed.
				$tmpEngine = clone $this->engine;
				$tmpEngine->setDirectory($tmpDir);
				
				return $tmpEngine->render($tmpName, $mergedData);
			} catch (\Throwable $e) {
				$snippet = strlen($templateString) > 50 ? substr($templateString, 0, 50) . '...' : $templateString;
				throw new TemplateRenderException($snippet, "Failed to render template string: " . $e->getMessage(), $e);
			} finally {
				// Always clean up the temporary file, even on failure
				if ($tmpFile !== null && file_exists($tmpFile)) {
					unlink($tmpFile);
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
		}
		
		/**
		 * Checks if a template file exists and is accessible
		 * @param string $template Template name, optionally namespaced (e.g. 'admin::users/list')
		 * @return bool True if the template exists and is accessible
		 */
		public function exists(string $template): bool {
			try {
				return $this->engine->exists($template);
			} catch (\Exception $e) {
				return false;
			}
		}
		
		/**
		 * Plates is a native-PHP engine and compiles no intermediate cache files.
		 * This method is a no-op provided for interface compatibility.
		 * @return void
		 */
		public function clearCache(): void {
			// Intentional no-op: Plates executes templates directly as PHP files
			// and maintains no compiled cache that can be cleared.
		}
		
		/**
		 * Registers a custom function accessible inside all Plates templates
		 * as {$this->functionName(...args)}.
		 * @param string $name The function name
		 * @param callable $callback
		 * @return void
		 */
		public function registerFunction(string $name, callable $callback): void {
			try {
				$this->engine->registerFunction($name, $callback);
			} catch (\Exception $e) {
				error_log("PlatesTemplate: unable to register function '{$name}' ({$e->getMessage()})");
			}
		}
		
		/**
		 * Add a template directory, with an optional namespace prefix.
		 * Plates refers to namespaced directories as "folders".
		 * @param string $path The directory path to add
		 * @param string|null $namespace Optional namespace prefix
		 * @return void
		 */
		public function addPath(string $path, ?string $namespace = null): void {
			try {
				$key = $namespace ?? '';
				$this->paths[$key] = rtrim($path, '/\\');
				
				if ($namespace !== null && $namespace !== '') {
					// Register as a named Plates folder so templates can be addressed
					// as 'namespace::template' (e.g. 'admin::users/list')
					if ($this->engine->getFolders()->exists($namespace)) {
						$this->engine->getFolders()->remove($namespace);
					}
					
					$this->engine->addFolder($namespace, $this->paths[$key]);
				} else {
					// Update the default directory
					$this->engine->setDirectory($this->paths['']);
				}
			} catch (\Exception $e) {
				error_log("PlatesTemplate: unable to add path '{$path}' ({$e->getMessage()})");
			}
		}
		
		/**
		 * Get the underlying Plates Engine instance
		 * @return Engine
		 */
		public function getEngine(): Engine {
			return $this->engine;
		}
		
		/**
		 * Plates does not compile templates to a cache.
		 * This method always returns false and is provided for interface compatibility.
		 * @param string $template Template name, optionally namespaced
		 * @return bool Always false
		 */
		public function isCached(string $template): bool {
			return false;
		}
		
		/**
		 * Plates does not compile templates to a cache.
		 * This method is a no-op provided for interface compatibility.
		 * @param string $template Template name, optionally namespaced
		 * @return void
		 */
		public function clearTemplateCache(string $template): void {
			// Intentional no-op: Plates executes templates directly as PHP files
			// and maintains no compiled cache per template.
		}
		
	}