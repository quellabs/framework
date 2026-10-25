<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Migration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Migration\AbstractMigration;

	/**
	 * Unit tests for AbstractMigration — the base class migrations extend
	 * (see objectquel-migrations-implementation-plan.md, Phase 1).
	 */
	class AbstractMigrationTest extends TestCase {

		private function makeMigration(EntityManager $entityManager): AbstractMigration {
			return new class($entityManager) extends AbstractMigration {
				public function up(): void {
					$this->query('create Foo (id = integer)');
				}

				public function down(): void {
					$this->query('destroy Foo');
				}
			};
		}

		private function makeEntityManagerMock(): EntityManager {
			return $this->getMockBuilder(EntityManager::class)
				->disableOriginalConstructor()
				->onlyMethods(['executeQuery'])
				->getMock();
		}

		public function testUpDelegatesToEntityManagerExecuteQuery(): void {
			$entityManager = $this->makeEntityManagerMock();
			$entityManager->expects(self::once())
				->method('executeQuery')
				->with('create Foo (id = integer)')
				->willReturn(null);

			$this->makeMigration($entityManager)->up();
		}

		public function testDownDelegatesToEntityManagerExecuteQuery(): void {
			$entityManager = $this->makeEntityManagerMock();
			$entityManager->expects(self::once())
				->method('executeQuery')
				->with('destroy Foo')
				->willReturn(null);

			$this->makeMigration($entityManager)->down();
		}

		public function testCannotBeInstantiatedDirectly(): void {
			$this->assertTrue((new \ReflectionClass(AbstractMigration::class))->isAbstract());
		}

		/**
		 * No execute(raw sql) escape hatch exists — the migration base class
		 * only ever reaches the database through query(), which runs Quel
		 * text (see class docblock, "Deliberately has no execute(...)").
		 */
		public function testHasNoRawSqlExecuteMethod(): void {
			$methodNames = array_map(
				fn(\ReflectionMethod $method) => $method->getName(),
				(new \ReflectionClass(AbstractMigration::class))->getMethods()
			);

			$this->assertNotContains('execute', $methodNames);
		}
	}
