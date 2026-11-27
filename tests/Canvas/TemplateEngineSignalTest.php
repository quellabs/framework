<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Handlebars\HandlebarsTemplate;
	use Quellabs\Canvas\Inspector\EventCollector;
	use Quellabs\Canvas\Smarty\SmartyTemplate;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Quellabs\SignalHub\Signal;
	use Quellabs\SignalHub\SignalHub;
	use Quellabs\SignalHub\SignalHubLocator;

	/**
	 * Template engine instances alive at once (of one engine or of
	 * Smarty and Handlebars together) share the one 'debug.template.query'
	 * standalone signal the hub allows.
	 */
	class TemplateEngineSignalTest extends TestCase {

		private const string SIGNAL_NAME = 'debug.template.query';

		private SignalHub $hub;
		private string $workDir;

		/**
		 * Installs a fresh hub and a scratch directory for compile and cache output.
		 * @return void
		 */
		protected function setUp(): void {
			$this->hub = new SignalHub();
			SignalHubLocator::setInstance($this->hub);

			$this->workDir = sys_get_temp_dir() . '/template-signal-test-' . bin2hex(random_bytes(4));
			mkdir($this->workDir . '/templates', 0755, true);
			mkdir($this->workDir . '/compile', 0755, true);
			mkdir($this->workDir . '/cache', 0755, true);
		}

		/**
		 * Restores the default hub and removes the scratch directory.
		 * @return void
		 */
		protected function tearDown(): void {
			SignalHubLocator::setInstance(null);
			$this->removeDirectory($this->workDir);
		}

		/**
		 * The engines under test, by the factory method that builds one.
		 * @return array<string, array{string}>
		 */
		public static function engineProvider(): array {
			return [
				'smarty'     => ['makeSmarty'],
				'handlebars' => ['makeHandlebars'],
			];
		}

		/**
		 * A second instance no longer throws "already registered", and both
		 * instances report renders on the same signal.
		 * @dataProvider engineProvider
		 * @param string $factory
		 * @return void
		 */
		public function testTwoLiveInstancesShareTheSignal(string $factory): void {
			$collector = new EventCollector($this->hub);
			$first = $this->make($factory);
			$second = $this->make($factory);

			$this->renderFile($first, 'first');
			$this->renderFile($second, 'second');

			$this->assertSame(['first', 'second'], $this->renderedTemplates($collector));
		}

		/**
		 * Destroying one instance leaves the survivor's renders visible to an
		 * existing collector, and a new instance can still be created.
		 * @dataProvider engineProvider
		 * @param string $factory
		 * @return void
		 */
		public function testDestroyingOneInstanceKeepsTheOtherReporting(string $factory): void {
			$collector = new EventCollector($this->hub);
			$first = $this->make($factory);
			$second = $this->make($factory);

			unset($first);
			$this->renderFile($second, 'survivor');
			$third = $this->make($factory);
			$this->renderFile($third, 'third');

			$this->assertSame(['survivor', 'third'], $this->renderedTemplates($collector));
		}

		/**
		 * The last instance going away removes the signal from the hub.
		 * @dataProvider engineProvider
		 * @param string $factory
		 * @return void
		 */
		public function testLastInstanceUnregistersTheSignal(string $factory): void {
			$template = $this->make($factory);
			$this->assertTrue($this->hub->hasSignal(self::SIGNAL_NAME));

			unset($template);

			$this->assertFalse($this->hub->hasSignal(self::SIGNAL_NAME));
		}

		/**
		 * An instance never removes a different signal that was registered
		 * under the same name after its own was unregistered.
		 * @dataProvider engineProvider
		 * @param string $factory
		 * @return void
		 */
		public function testDestructorLeavesAnotherSignalWithTheSameNameAlone(string $factory): void {
			$template = $this->make($factory);
			$this->hub->unregisterSignal($this->hub->getSignal(self::SIGNAL_NAME) ?? throw new \LogicException('Signal missing.'));
			$replacement = new Signal(self::SIGNAL_NAME);
			$this->hub->registerSignal($replacement);

			unset($template);

			$this->assertSame($replacement, $this->hub->getSignal(self::SIGNAL_NAME));
		}

		/**
		 * A Smarty and a Handlebars instance alive at once share the signal,
		 * whichever engine is created first.
		 * @return void
		 */
		public function testSmartyAndHandlebarsShareTheSignal(): void {
			$collector = new EventCollector($this->hub);
			$handlebars = $this->makeHandlebars();
			$smarty = $this->makeSmarty();

			$this->renderFile($handlebars, 'handlebars');
			$this->renderFile($smarty, 'smarty');

			$this->assertSame(['handlebars', 'smarty'], $this->renderedTemplates($collector));
		}

		/**
		 * Builds an engine with the named factory method.
		 * @param string $factory 'makeSmarty' or 'makeHandlebars'
		 * @return TemplateEngineInterface
		 */
		private function make(string $factory): TemplateEngineInterface {
			if ($factory === 'makeSmarty') {
				return $this->makeSmarty();
			}

			return $this->makeHandlebars();
		}

		/**
		 * Builds a SmartyTemplate writing into the scratch directory.
		 * @return SmartyTemplate
		 */
		private function makeSmarty(): SmartyTemplate {
			return new SmartyTemplate([
				'template_dir'   => $this->workDir . '/templates/',
				'compile_dir'    => $this->workDir . '/compile/',
				'cache_dir'      => $this->workDir . '/cache/',
				'debugging'      => false,
				'caching'        => 0,
				'clear_compiled' => true,
				'cache_lifetime' => null,
				'security'       => null,
				'globals'        => [],
			]);
		}

		/**
		 * Builds a HandlebarsTemplate writing into the scratch directory.
		 * @return HandlebarsTemplate
		 */
		private function makeHandlebars(): HandlebarsTemplate {
			return new HandlebarsTemplate([
				'template_dir' => $this->workDir . '/templates/',
				'compile_dir'  => $this->workDir . '/compile/',
				'strict_mode'  => false,
				'standalone'   => true,
				'helpers'      => [],
				'partials'     => [],
				'globals'      => [],
			]);
		}

		/**
		 * Writes a one-line template named $name for $engine and renders it.
		 * Files rather than strings, because HandlebarsTemplate::renderString() fails to compile.
		 * @param TemplateEngineInterface $engine
		 * @param string $name Template name without extension
		 * @return void
		 */
		private function renderFile(TemplateEngineInterface $engine, string $name): void {
			$file = $name . ($engine instanceof SmartyTemplate ? '.tpl' : '.hbs');
			file_put_contents($this->workDir . '/templates/' . $file, $name);

			$engine->render($file);
		}

		/**
		 * The names (without extension) of the templates the collector saw rendered, in order.
		 * @param EventCollector $collector
		 * @return list<string>
		 */
		private function renderedTemplates(EventCollector $collector): array {
			$templates = array_column(array_column($collector->getEventsBySignal(self::SIGNAL_NAME), 'data'), 'template');

			return array_map(static fn(mixed $template): string => is_string($template) ? pathinfo($template, PATHINFO_FILENAME) : '', $templates);
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
