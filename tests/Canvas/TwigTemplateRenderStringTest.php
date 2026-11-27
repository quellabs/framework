<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Twig\TwigTemplate;
	use Quellabs\Contracts\Templates\TemplateRenderException;

	/**
	 * TwigTemplate::renderString() renders through the main environment,
	 * with and without the compiled-template cache.
	 */
	class TwigTemplateRenderStringTest extends TestCase {

		private string $workDir;

		/**
		 * Creates scratch template and cache directories with one template file.
		 * @return void
		 */
		protected function setUp(): void {
			$this->workDir = sys_get_temp_dir() . '/twig-render-string-' . bin2hex(random_bytes(4));
			mkdir($this->workDir . '/templates', 0755, true);
			mkdir($this->workDir . '/cache', 0755, true);
			file_put_contents($this->workDir . '/templates/page.twig', 'Page {{ x }}');
		}

		/**
		 * Removes the scratch directories.
		 * @return void
		 */
		protected function tearDown(): void {
			$this->removeDirectory($this->workDir);
		}

		/**
		 * Both cache settings.
		 * @return array<string, array{bool}>
		 */
		public static function cachingProvider(): array {
			return [
				'no cache' => [false],
				'cache'    => [true],
			];
		}

		/**
		 * Variables are substituted and HTML-escaped, and strings can be
		 * rendered repeatedly.
		 * @dataProvider cachingProvider
		 * @param bool $caching
		 * @return void
		 */
		public function testRendersAndEscapesRepeatedly(bool $caching): void {
			$template = $this->makeTemplate($caching);

			$this->assertSame('Hello &lt;b&gt;', $template->renderString('Hello {{ x }}', ['x' => '<b>']));
			$this->assertSame('Hello again', $template->renderString('Hello {{ x }}', ['x' => 'again']));
			$this->assertSame('Bye you', $template->renderString('Bye {{ x }}', ['x' => 'you']));
		}

		/**
		 * Filters, functions and globals registered on the engine apply to
		 * string templates, and file rendering still works afterwards.
		 * @dataProvider cachingProvider
		 * @param bool $caching
		 * @return void
		 */
		public function testUsesTheEnginesRegistrationsAndLeavesFileRenderingIntact(bool $caching): void {
			$template = $this->makeTemplate($caching);
			$template->registerFilter('shout', static fn(string $value): string => strtoupper($value));
			$template->registerFunction('greet', static fn(string $name): string => "hi {$name}");
			$template->addGlobal('site', 'Canvas');

			$this->assertSame('ABC hi x Canvas', $template->renderString('{{ "abc"|shout }} {{ greet("x") }} {{ site }}'));
			$this->assertSame('Page file', $template->render('page.twig', ['x' => 'file']));
		}

		/**
		 * A syntax error is reported as a TemplateRenderException.
		 * @return void
		 */
		public function testSyntaxErrorBecomesTemplateRenderException(): void {
			$this->expectException(TemplateRenderException::class);

			$this->makeTemplate(false)->renderString('{{ unclosed');
		}

		/**
		 * Builds a TwigTemplate on the scratch directories.
		 * @param bool $caching
		 * @return TwigTemplate
		 */
		private function makeTemplate(bool $caching): TwigTemplate {
			return new TwigTemplate([
				'template_dir'     => $this->workDir . '/templates',
				'cache_dir'        => $this->workDir . '/cache',
				'debugging'        => false,
				'caching'          => $caching,
				'auto_reload'      => true,
				'strict_variables' => false,
				'charset'          => 'UTF-8',
				'autoescape'       => 'html',
				'extensions'       => [],
			]);
		}

		/**
		 * Deletes $path and everything below it.
		 * @param string $path
		 * @return void
		 */
		private function removeDirectory(string $path): void {
			if (!is_dir($path)) {
				return;
			}

			/** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $items */
			$items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

			/** @var \SplFileInfo $item */
			foreach ($items as $item) {
				if ($item->isDir()) {
					rmdir($item->getPathname());
				} else {
					unlink($item->getPathname());
				}
			}

			rmdir($path);
		}
	}
