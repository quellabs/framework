<?php
	
	namespace Quellabs\Canvas\Handlebars;
	
	use LightnCandy\LightnCandy;
	use Quellabs\SignalHub\Signal;
	use Quellabs\SignalHub\SignalHubLocator;
	use Quellabs\Contracts\Templates\TemplateRenderException;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	
	class HandlebarsTemplate implements TemplateEngineInterface {
		
		/**
		 * @var array Configuration data provided by ServiceProvider
		 */
		private array $config;
		
		/**
		 * @var array Global variables available to all templates
		 */
		private array $globals = [];
		
		/**
		 * @var array<string, callable> Registered Handlebars helpers
		 */
		private array $helpers = [];
		
		/**
		 * @var array<string, string> Registered partial template strings, keyed by name
		 */
		private array $partials = [];
		
		/**
		 * @var Signal Signal used to send debug data to Canvas
		 */
		private Signal $templateSignal;
		
		/**
		 * HandlebarsTemplate constructor
		 * @param array $configuration
		 */
		public function __construct(array $configuration) {
			// Store the configuration
			$this->config = $configuration;
			
			// Ensure compile directory exists
			if (!is_dir($configuration['compile_dir'])) {
				mkdir($configuration['compile_dir'], 0755, true);
			}
			
			// Register signal
			$signalHub = SignalHubLocator::getInstance();
			$this->templateSignal = new Signal('debug.template.query');
			$signalHub->registerSignal($this->templateSignal);
			
			// Register global helpers from configuration
			if (!empty($configuration['helpers']) && is_array($configuration['helpers'])) {
				foreach ($configuration['helpers'] as $name => $callback) {
					$this->registerHelper($name, $callback);
				}
			}
			
			// Register partials from configuration
			if (!empty($configuration['partials']) && is_array($configuration['partials'])) {
				foreach ($configuration['partials'] as $name => $content) {
					$this->registerPartial($name, $content);
				}
			}
			
			// Register global variables from configuration
			if (!empty($configuration['globals']) && is_array($configuration['globals'])) {
				foreach ($configuration['globals'] as $key => $value) {
					$this->addGlobal($key, $value);
				}
			}
		}
		
		/**
		 * Remove template signal from hub
		 */
		public function __destruct() {
			$signalHub = SignalHubLocator::getInstance();
			$signalHub->unregisterSignal($this->templateSignal);
		}
		
		/**
		 * Renders a template file using Handlebars via LightnCandy
		 * @param string $template The template file name/path to render
		 * @param array $data Associative array of variables to pass to the template
		 * @return string The rendered template content as a string
		 * @throws TemplateRenderException If template rendering fails for any reason
		 */
		public function render(string $template, array $data = []): string {
			return $this->renderTemplate($template, $data, false);
		}
		
		/**
		 * Renders a Handlebars template string with the provided data
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
		 * @param mixed $value The value to assign
		 * @return void
		 */
		public function addGlobal(string $key, mixed $value): void {
			$this->globals[$key] = $value;
		}
		
		/**
		 * Checks if a template file exists and is accessible
		 * @param string $template The template file name/path to check
		 * @return bool True if the template exists and is accessible
		 */
		public function exists(string $template): bool {
			$templatePath = $this->resolveTemplatePath($template);
			return file_exists($templatePath) && is_readable($templatePath);
		}
		
		/**
		 * Clears all compiled Handlebars templates from the compile directory.
		 * Unlike output-cache engines (Smarty), LightnCandy's "cache" is compiled
		 * PHP files. Clearing them forces recompilation on next render.
		 * @return void
		 * @throws \Exception If cache clearing fails
		 */
		public function clearCache(): void {
			try {
				$compileDir = $this->config['compile_dir'];
				
				if (!is_dir($compileDir)) {
					return;
				}
				
				$files = glob($compileDir . '*.php');
				
				if ($files === false) {
					return;
				}
				
				foreach ($files as $file) {
					if (is_file($file) && !unlink($file)) {
						throw new \Exception("Failed to delete compiled template: {$file}");
					}
				}
			} catch (\Exception $e) {
				throw new \Exception("Failed to clear Handlebars compile cache: " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Register a Handlebars helper function.
		 * Helpers are callable blocks that can be invoked in templates as {{helperName arg}}.
		 * @param string $name The helper name as used in templates
		 * @param callable $callback The function that implements the helper
		 * @return void
		 */
		public function registerHelper(string $name, callable $callback): void {
			$this->helpers[$name] = $callback;
		}
		
		/**
		 * Register a named partial template string.
		 * Partials allow templates to include reusable fragments via {{> partialName}}.
		 * @param string $name The partial name as used in templates
		 * @param string $templateContent The Handlebars template content for this partial
		 * @return void
		 */
		public function registerPartial(string $name, string $templateContent): void {
			$this->partials[$name] = $templateContent;
		}
		
		/**
		 * Clears the compiled cache for a single template file.
		 * More efficient than clearCache() when only one template has changed.
		 * @param string $template The specific template file to clear from compile cache
		 * @return void
		 */
		public function clearTemplateCache(string $template): void {
			$compiledPath = $this->getCompiledPath($template);
			
			if (file_exists($compiledPath) && !unlink($compiledPath)) {
				error_log("HandlebarsTemplate: unable to clear compiled template: {$compiledPath}");
			}
		}
		
		/**
		 * Check if a specific template has a compiled PHP file in the cache.
		 * @param string $template The template file to check
		 * @return bool True if a compiled version exists and is up-to-date
		 */
		public function isCached(string $template): bool {
			$compiledPath = $this->getCompiledPath($template);
			
			if (!file_exists($compiledPath)) {
				return false;
			}
			
			// Check if the compiled file is newer than the source template
			$templatePath = $this->resolveTemplatePath($template);
			
			if (!file_exists($templatePath)) {
				return false;
			}
			
			return filemtime($compiledPath) >= filemtime($templatePath);
		}
		
		/**
		 * Internal method to handle both file and string template rendering
		 * @param string $template Template file name/path or template string content
		 * @param array $data Variables to pass to the template
		 * @param bool $isString Whether $template is raw content (true) or a file path (false)
		 * @return string Rendered output
		 * @throws TemplateRenderException
		 */
		private function renderTemplate(string $template, array $data, bool $isString): string {
			try {
				$start = microtime(true);
				
				// Merge globals and local data; local takes precedence
				$mergedData = array_merge($this->globals, $data);
				
				// Get or compile the renderer
				$renderer = $isString
					? $this->compileString($template)
					: $this->compileFile($template);
				
				// Execute the compiled renderer
				$result = $renderer($mergedData);
				
				// Emit debug signal
				$this->templateSignal->emit([
					'template'          => $template,
					'bound_parameters'  => $data,
					'is_string'         => $isString,
					'execution_time_ms' => microtime(true) - $start,
				]);
				
				return $result;
				
			} catch (TemplateRenderException $e) {
				throw $e;
			} catch (\Exception $e) {
				if ($isString) {
					$snippet = strlen($template) > 50 ? substr($template, 0, 50) . '...' : $template;
					$errorContext = "template string '{$snippet}'";
				} else {
					$errorContext = "template '{$template}'";
				}
				
				throw new TemplateRenderException("Failed to render {$errorContext}: ", $e->getMessage(), $e);
			}
		}
		
		/**
		 * Compile a template file to a PHP callable, using the compile cache.
		 * Recompilation only happens when the source file is newer than the cached version.
		 * @param string $template Template filename relative to template_dir
		 * @return callable The compiled renderer
		 * @throws \RuntimeException If the template file cannot be found or compiled
		 */
		private function compileFile(string $template): callable {
			$templatePath = $this->resolveTemplatePath($template);
			
			if (!file_exists($templatePath)) {
				throw new \RuntimeException("Template file not found: {$templatePath}");
			}
			
			$compiledPath = $this->getCompiledPath($template);
			
			// Recompile if no compiled file exists or the source is newer
			if (!file_exists($compiledPath) || filemtime($compiledPath) < filemtime($templatePath)) {
				$source = file_get_contents($templatePath);
				
				if ($source === false) {
					throw new \RuntimeException("Failed to read template file: {$templatePath}");
				}
				
				$this->compileAndSave($source, $compiledPath);
			}
			
			return require $compiledPath;
		}
		
		/**
		 * Compile a template string to a PHP callable.
		 * Template strings are never cached to disk — they compile on every call.
		 * Use renderFile() for performance-sensitive paths.
		 * @param string $templateString Raw Handlebars template content
		 * @return callable The compiled renderer
		 * @throws \RuntimeException If compilation fails
		 */
		private function compileString(string $templateString): callable {
			$flags = $this->buildFlags();
			
			$compiled = LightnCandy::compile($templateString, [
				'flags'    => $flags,
				'helpers'  => $this->helpers,
				'partials' => $this->partials,
			]);
			
			if ($compiled === false) {
				throw new \RuntimeException("LightnCandy failed to compile template string");
			}
			
			// eval is unavoidable here: LightnCandy outputs PHP source that must be executed.
			// String templates are never user-supplied in normal Canvas usage.
			/** @noinspection PhpUseFunctionInsteadOfLanguageConstructShouldBeUsedInspection */
			return eval('?>' . $compiled);
		}
		
		/**
		 * Compile Handlebars source and write the resulting PHP renderer to disk.
		 * @param string $source Raw Handlebars template content
		 * @param string $compiledPath Destination path for the compiled PHP file
		 * @return void
		 * @throws \RuntimeException If compilation or file write fails
		 */
		private function compileAndSave(string $source, string $compiledPath): void {
			$flags = $this->buildFlags();
			
			$compiled = LightnCandy::compile($source, [
				'flags'    => $flags,
				'helpers'  => $this->helpers,
				'partials' => $this->partials,
			]);
			
			if ($compiled === false) {
				throw new \RuntimeException("LightnCandy failed to compile template: {$compiledPath}");
			}
			
			// Write atomically: write to a temp file then rename to avoid partial reads
			$tmpPath = $compiledPath . '.tmp.' . getmypid();
			
			if (file_put_contents($tmpPath, '<?php ' . $compiled) === false) {
				throw new \RuntimeException("Failed to write compiled template to: {$compiledPath}");
			}
			
			if (!rename($tmpPath, $compiledPath)) {
				unlink($tmpPath);
				throw new \RuntimeException("Failed to move compiled template to: {$compiledPath}");
			}
		}
		
		/**
		 * Build LightnCandy compiler flags from configuration.
		 * @return int Bitmask of LightnCandy FLAG_* constants
		 */
		private function buildFlags(): int {
			// FLAG_HANDLEBARSJS: full Handlebars.js compatibility (block helpers, partials, etc.)
			// FLAG_ERROR_EXCEPTION: throw exceptions on compile errors rather than returning false
			$flags = LightnCandy::FLAG_HANDLEBARSJS | LightnCandy::FLAG_ERROR_EXCEPTION;
			
			if (!empty($this->config['strict_mode'])) {
				// FLAG_STRICT: throw on missing variables rather than rendering empty string
				$flags |= LightnCandy::FLAG_STRICT;
			}
			
			if (!empty($this->config['standalone'])) {
				// FLAG_BESTPERFORMANCE: outputs a self-contained PHP closure with no runtime dependency
				$flags |= LightnCandy::FLAG_BESTPERFORMANCE;
			}
			
			return $flags;
		}
		
		/**
		 * Resolve a template name to its full filesystem path.
		 * @param string $template Template filename (e.g. 'home.hbs' or 'partials/header.hbs')
		 * @return string Absolute path to the template file
		 */
		private function resolveTemplatePath(string $template): string {
			return rtrim($this->config['template_dir'], '/') . '/' . ltrim($template, '/');
		}
		
		/**
		 * Derive the compiled PHP file path for a given template name.
		 * Uses a hash of the full path so filenames are unique and collision-free.
		 * @param string $template Template filename
		 * @return string Absolute path to the compiled PHP file
		 */
		private function getCompiledPath(string $template): string {
			$hash = md5($this->resolveTemplatePath($template));
			return rtrim($this->config['compile_dir'], '/') . '/' . $hash . '.php';
		}
	}