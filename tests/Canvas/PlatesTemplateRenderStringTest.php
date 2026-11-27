<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Plates\PlatesTemplate;

	/**
	 * PlatesTemplate::renderString() renders without changing the engine's
	 * template directory, for any configured file extension.
	 */
	class PlatesTemplateRenderStringTest extends TestCase {

		private string $workDir;

		/**
		 * Creates scratch template directories.
		 * @return void
		 */
		protected function setUp(): void {
			$this->workDir = sys_get_temp_dir() . '/plates-render-string-' . bin2hex(random_bytes(4));
			mkdir($this->workDir . '/templates', 0755, true);
			mkdir($this->workDir . '/admin', 0755, true);
		}

		/**
		 * Removes the scratch directories.
		 * @return void
		 */
		protected function tearDown(): void {
			$this->removeDirectory($this->workDir);
		}

		/**
		 * File rendering, default and namespaced, still works after a string render.
		 * @return void
		 */
		public function testFileRenderingStillWorksAfterRenderString(): void {
			file_put_contents($this->workDir . '/templates/page.php', 'Page <?= $this->e($x) ?>');
			file_put_contents($this->workDir . '/admin/panel.php', 'Admin <?= $this->e($x) ?>');
			$template = $this->makeTemplate('php');
			$template->addPath($this->workDir . '/admin', 'admin');

			$this->assertSame('Hello &lt;b&gt;', $template->renderString('Hello <?= $this->e($x) ?>', ['x' => '<b>']));
			$this->assertSame('Page file', $template->render('page', ['x' => 'file']));
			$this->assertSame('Admin file', $template->render('admin::panel', ['x' => 'file']));
			$this->assertSame('Again', $template->renderString('<?= $x ?>', ['x' => 'Again']));
		}

		/**
		 * The temporary file gets the configured extension, so a non-default one works.
		 * @return void
		 */
		public function testRendersWithANonDefaultExtension(): void {
			file_put_contents($this->workDir . '/templates/page.phtml', 'Page <?= $x ?>');
			$template = $this->makeTemplate('phtml');

			$this->assertSame('String ok', $template->renderString('String <?= $x ?>', ['x' => 'ok']));
			$this->assertSame('Page ok', $template->render('page', ['x' => 'ok']));
		}

		/**
		 * Functions and globals registered on the engine apply to string templates.
		 * @return void
		 */
		public function testUsesRegisteredFunctionsAndGlobals(): void {
			$template = $this->makeTemplate('php');
			$template->registerFunction('shout', static fn(string $value): string => strtoupper($value));
			$template->addGlobal('site', 'Canvas');

			$this->assertSame('ABC Canvas', $template->renderString('<?= $this->shout("abc") ?> <?= $site ?>'));
		}

		/**
		 * Builds a PlatesTemplate on the scratch template directory.
		 * @param string $extension
		 * @return PlatesTemplate
		 */
		private function makeTemplate(string $extension): PlatesTemplate {
			return new PlatesTemplate([
				'template_dir' => $this->workDir . '/templates',
				'extension'    => $extension,
				'functions'    => [],
				'paths'        => [],
				'globals'      => [],
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
