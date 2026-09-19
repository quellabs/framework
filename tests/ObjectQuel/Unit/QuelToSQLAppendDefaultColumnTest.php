<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLAppend;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLReplace;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLUpsert;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Compile-time coverage for QuelToSQLAppend's transparent default-column
	 * injection: a column the caller doesn't supply, but that carries a
	 * declared @Orm\Column(default=...), is added to the generated INSERT
	 * with that literal value instead of being silently omitted and left to
	 * whatever DEFAULT (if any) the table's own DDL happens to declare.
	 *
	 * App\Entities\DefaultColumnEntity's `priority` column deliberately uses
	 * a falsy default (0) — the exact value Column::hasDefault()'s old
	 * `!empty()` implementation got wrong (see ColumnTest). This class'
	 * collectDefaultColumnsToInit() reads the raw metadata default value
	 * directly rather than through hasDefault(), so it isn't itself affected
	 * by that bug, but the fixture reuses the same falsy value to keep both
	 * regressions anchored to one deliberately-awkward default.
	 */
	class QuelToSQLAppendDefaultColumnTest extends TestCase {

		private function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		private function compile(string $query, array $parameters = []): string {
			$em = $this->em();
			$ast = (new Parser(new Lexer($query), $em->getEntityStore()))->parse();
			self::assertInstanceOf(AstAppend::class, $ast);

			$platform = new FakePlatformCapabilities('mysql');
			$versionValueHandler = $em->getUnitOfWork()->getVersionValueHandler();
			$replaceCompiler = new QuelToSQLReplace($em->getEntityStore(), $platform, $versionValueHandler);
			$upsertCompiler = new QuelToSQLUpsert($em->getEntityStore(), $platform, $replaceCompiler);
			$compiler = new QuelToSQLAppend($em, $platform, $upsertCompiler, $versionValueHandler);

			return $compiler->convertToSQL($ast, $parameters)->primarySql;
		}

		public function testOmittedDefaultedColumnIsInjectedWithItsDeclaredValue(): void {
			$sql = $this->compile('
				range of d is App\Entities\DefaultColumnEntity
				append to d (name = :name)
			', ['name' => 'widget']);

			self::assertSame(
				'INSERT INTO `default_column_test` (`name`, `priority`) VALUES (:name, 0)',
				$sql
			);
		}

		public function testExplicitlySuppliedValueOverridesTheDeclaredDefault(): void {
			$sql = $this->compile('
				range of d is App\Entities\DefaultColumnEntity
				append to d (name = :name, priority = :priority)
			', ['name' => 'widget', 'priority' => 5]);

			self::assertSame(
				'INSERT INTO `default_column_test` (`name`, `priority`) VALUES (:name, :priority)',
				$sql
			);
		}
	}
