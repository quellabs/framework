<?php
	
	namespace Quellabs\Sculpt\Contracts;
	
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Support\ComposerUtils;
	
	abstract class StubCommand extends CommandBase {
		
		/**
		 * Return stubs to copy: stub path (relative to package) => target path (relative to project root)
		 * Use {engine} in paths for template engine substitution.
		 * @return array<string, string>
		 */
		abstract protected function getStubs(): array;
		
		/**
		 * Return token replacements applied to all stub file contents.
		 * StubCommand provides defaults; override to add or change tokens.
		 * @return array<string, string>
		 */
		protected function getTokens(): array {
			return [
				'{{ namespace }}' => ComposerUtils::getRootNamespace(),
				'{{ date }}'      => date('Y-m-d'),
			];
		}
		
		/**
		 * Return the template engine identifier from config/app.php.
		 * Falls back to 'php' if not set.
		 */
		protected function resolveTemplateEngine(): string {
			$configFile = ComposerUtils::getProjectRoot() . '/config/app.php';
			
			if (!file_exists($configFile)) {
				return 'php';
			}
			
			$config = require $configFile;
			return $config['template_engine'] ?? 'smarty';
		}
		
		/**
		 * Execute: resolve engine, copy stubs with token replacement.
		 */
		public function execute(ConfigurationManager $config): int {
			$force  = $config->hasFlag('force');
			$engine = $this->resolveTemplateEngine();
			$root   = ComposerUtils::getProjectRoot();
			$stubs  = $this->getStubs();
			$tokens = $this->getTokens();
			
			foreach ($stubs as $stubRelative => $targetRelative) {
				// Resolve {engine} placeholder in paths
				$stubRelative   = str_replace('{engine}', $engine, $stubRelative);
				$targetRelative = str_replace('{engine}', $engine, $targetRelative);
				
				// Stub file missing — skip with warning, don't abort the whole command
				$stubPath   = $this->resolveStubPath($stubRelative);
				$targetPath = $root . '/' . ltrim($targetRelative, '/');
				
				if (!file_exists($stubPath)) {
					$this->output->warning("Stub not found for engine '{$engine}': {$stubRelative} — skipping.");
					continue;
				}
				
				// Conflict handling
				if (file_exists($targetPath) && !$force) {
					if (!$this->input->confirm("File already exists: {$targetRelative}. Overwrite?", false)) {
						$this->output->writeLn("<dim>  Skipped:  {$targetRelative}</dim>");
						continue;
					}
				}
				
				// Ensure target directory exists
				$targetDir = dirname($targetPath);
				
				if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
					$this->output->error("Could not create directory: {$targetDir}");
					return 1;
				}
				
				// Apply token replacement and write
				$contents = file_get_contents($stubPath);
				$contents = str_replace(array_keys($tokens), array_values($tokens), $contents);
				
				file_put_contents($targetPath, $contents);
				$this->output->success("Created:  {$targetRelative}");
			}
			
			return 0;
		}
		
		/**
		 * Resolve absolute path to a stub file.
		 * Uses the provider's location as the base — stubs live next to the ServiceProvider.
		 * @throws \ReflectionException
		 */
		protected function resolveStubPath(string $relative): string {
			$reflection  = new \ReflectionClass($this->provider);
			$providerDir = dirname($reflection->getFileName());
			return $providerDir . '/../stubs/' . ltrim($relative, '/');
		}
	}