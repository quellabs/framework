<?php

	declare(strict_types=1);

	namespace Quellabs\ObjectQuel\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ReflectionManagement\EntityLocator;
	use ReflectionClass;

	/**
	 * Unit tests for EntityLocator::extractEntityNameFromFile().
	 *
	 * Regression coverage for a bug where the class name was extracted with a
	 * regex over the raw file contents. A class-level docblock containing the
	 * literal sequence "class" + whitespace + word (e.g. "one query class from
	 * a log") matched before the real `class Foo {` declaration, so the wrong
	 * name was extracted and the entity was silently dropped from discovery.
	 */
	class EntityLocatorTest extends TestCase {

		private array $tempFiles = [];

		protected function tearDown(): void {
			foreach ($this->tempFiles as $file) {
				if (is_file($file)) {
					unlink($file);
				}
			}

			$this->tempFiles = [];
		}

		/**
		 * Writes $contents to a temp .php file and returns the extracted FQCN.
		 */
		private function extract(string $contents): ?string {
			$file = tempnam(sys_get_temp_dir(), 'el_test_') . '.php';
			file_put_contents($file, $contents);
			$this->tempFiles[] = $file;

			$locator = (new ReflectionClass(EntityLocator::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(EntityLocator::class, 'extractEntityNameFromFile');
			$method->setAccessible(true);

			return $method->invoke($locator, $file);
		}

		public function testDocblockContainingClassWordDoesNotShadowRealDeclaration(): void {
			$contents = <<<'PHP'
<?php

namespace App\Entities;

/**
 * This entity represents one query class from a processed log.
 * It's also used when importing a class into the monitoring pipeline.
 */
class SlowLogQueryClassEntity {
	private int $id;
}
PHP;

			$this->assertSame(
				'App\\Entities\\SlowLogQueryClassEntity',
				$this->extract($contents)
			);
		}

		public function testPlainClassDeclarationIsExtracted(): void {
			$contents = <<<'PHP'
<?php

namespace App\Entities;

class PlainEntity {
	private int $id;
}
PHP;

			$this->assertSame(
				'App\\Entities\\PlainEntity',
				$this->extract($contents)
			);
		}

		public function testClassConstantReferenceBeforeDeclarationIsIgnored(): void {
			$contents = <<<'PHP'
<?php

namespace App\Entities;

use Some\Other\Thing;

$defaultFqcn = Thing::class;

class RealEntity {
	private $x = Thing::class;
}
PHP;

			$this->assertSame(
				'App\\Entities\\RealEntity',
				$this->extract($contents)
			);
		}

		public function testAnonymousClassBeforeDeclarationIsIgnored(): void {
			$contents = <<<'PHP'
<?php

namespace App\Entities;

$anon = new class {
	public $y = 1;
};

class RealEntity {
	private int $id;
}
PHP;

			$this->assertSame(
				'App\\Entities\\RealEntity',
				$this->extract($contents)
			);
		}
	}
