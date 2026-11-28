<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Handlebars\HandlebarsTemplate;
	use Quellabs\SignalHub\SignalHub;
	use Quellabs\SignalHub\SignalHubLocator;

	/**
	 * HandlebarsTemplate::renderString() compiles and renders a template
	 * string, with and without LightnCandy's standalone mode.
	 */
	class HandlebarsTemplateRenderStringTest extends TestCase {

		private string $compileDir;

		/**
		 * Installs a fresh hub and picks a scratch compile directory.
		 * @return void
		 */
		protected function setUp(): void {
			SignalHubLocator::setInstance(new SignalHub());
			$this->compileDir = sys_get_temp_dir() . '/handlebars-render-string-' . bin2hex(random_bytes(4)) . '/';
		}

		/**
		 * Restores the default hub and removes the compile directory.
		 * @return void
		 */
		protected function tearDown(): void {
			SignalHubLocator::setInstance(null);

			if (is_dir($this->compileDir)) {
				rmdir($this->compileDir);
			}
		}

		/**
		 * Both compile modes.
		 * @return array<string, array{bool}>
		 */
		public static function standaloneProvider(): array {
			return [
				'runtime'    => [false],
				'standalone' => [true],
			];
		}

		/**
		 * Variables are substituted and HTML-escaped.
		 * @dataProvider standaloneProvider
		 * @param bool $standalone
		 * @return void
		 */
		public function testRendersAndEscapesVariables(bool $standalone): void {
			$template = $this->makeTemplate($standalone);

			$this->assertSame('Hello &lt;b&gt;World&lt;/b&gt;', $template->renderString('Hello {{name}}', ['name' => '<b>World</b>']));
		}

		/**
		 * Block helpers work, and the same and different strings can be
		 * rendered repeatedly in one process.
		 * @dataProvider standaloneProvider
		 * @param bool $standalone
		 * @return void
		 */
		public function testRendersRepeatedlyWithBlockHelpers(bool $standalone): void {
			$template = $this->makeTemplate($standalone);

			$this->assertSame('yes', $template->renderString('{{#if on}}yes{{else}}no{{/if}}', ['on' => true]));
			$this->assertSame('no', $template->renderString('{{#if on}}yes{{else}}no{{/if}}', ['on' => false]));
			$this->assertSame('a,b,', $template->renderString('{{#each items}}{{this}},{{/each}}', ['items' => ['a', 'b']]));
		}

		/**
		 * Builds a HandlebarsTemplate for $standalone mode.
		 * @param bool $standalone
		 * @return HandlebarsTemplate
		 */
		private function makeTemplate(bool $standalone): HandlebarsTemplate {
			return new HandlebarsTemplate([
				'template_dir' => sys_get_temp_dir() . '/',
				'compile_dir'  => $this->compileDir,
				'strict_mode'  => false,
				'standalone'   => $standalone,
				'helpers'      => [],
				'partials'     => [],
				'globals'      => [],
			]);
		}
	}
