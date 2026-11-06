<?php

	namespace Quellabs\CanvasAuthorization\Sculpt;

	use Quellabs\CanvasAuthorization\SharedAuthStubsInstaller;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\StubCommand;

	/**
	 * Base for each ORM package's install:auth-{orm} command. Merges the
	 * shared, ORM-agnostic stubs from canvas-authorization with the ORM
	 * package's own Entities/Controllers/Aspects stubs, then installs all
	 * of them in a single StubCommand::execute() pass.
	 */
	abstract class OrmAuthInstallCommand extends StubCommand {

		/**
		 * Maps a template engine name to the file extension its templates use.
		 */
		private const array TEMPLATE_EXTENSIONS = [
			'smarty' => 'tpl',
			'blade'  => 'blade.php',
			'latte'  => 'latte',
			'php'    => 'php',
			'twig'   => 'twig',
		];

		/**
		 * Return this ORM package's own stubs: stub path (relative to its own
		 * stubs/) => target path (relative to project root). Merged with the
		 * shared stubs by getStubs() below.
		 * @return array<string, string>
		 */
		abstract protected function getOrmStubs(): array;

		/**
		 * Print the "Next Steps" guidance specific to this ORM, called after
		 * a successful install.
		 * @return void
		 */
		abstract protected function showNextSteps(): void;

		/**
		 * Execute the command, then show ORM-specific next steps on success.
		 * @param ConfigurationManager $config
		 * @return int Exit code (0 = success, 1 = error)
		 */
		public function execute(ConfigurationManager $config): int {
			$this->output->writeLn("<green>Installing Authentication System</green>");
			$this->output->writeLn("");

			$exitCode = parent::execute($config);

			if ($exitCode === 0) {
				$this->output->writeLn("");
				$this->output->writeLn("<green>Next Steps:</green>");
				$this->output->writeLn("");
				$this->showNextSteps();
			}

			return $exitCode;
		}

		/**
		 * Returns token list, adding the template-extension token the
		 * Controllers stubs use on top of StubCommand's defaults.
		 * @return array|string[]
		 */
		protected function getTokens(): array {
			return array_merge(parent::getTokens(), [
				'{{ template_ext }}' => $this->resolveTemplateExtension(),
			]);
		}

		/**
		 * Merges the shared stubs (from canvas-authorization) with this ORM
		 * package's own stubs.
		 * @return array<string, string>
		 */
		protected function getStubs(): array {
			$engine = $this->resolveTemplateEngine();
			$ext = $this->resolveTemplateExtension();

			return array_merge(SharedAuthStubsInstaller::getStubs($engine, $ext), $this->getOrmStubs());
		}

		/**
		 * Resolves a stub's absolute path, preferring this ORM package's own
		 * stubs/ directory and falling back to canvas-authorization's shared
		 * stubs/ directory for the entries getOrmStubs() doesn't provide.
		 * @param string $relative
		 * @return string
		 */
		protected function resolveStubPath(string $relative): string {
			$ownPath = parent::resolveStubPath($relative);

			if (file_exists($ownPath)) {
				return $ownPath;
			}

			return SharedAuthStubsInstaller::getStubRoot() . ltrim($relative, '/');
		}

		/**
		 * Resolves the file extension used by the configured template engine.
		 * @return string
		 */
		private function resolveTemplateExtension(): string {
			return self::TEMPLATE_EXTENSIONS[$this->resolveTemplateEngine()] ?? 'tpl';
		}
	}
