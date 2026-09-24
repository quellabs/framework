<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Execution\Helpers\ProcessExpression;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * The zero value a nullable column's NULLs sort as, per column type and engine.
	 */
	class SortNullDefaultTest extends TestCase {

		/**
		 * @param string $columnType Abstract column type
		 * @param string $databaseType Engine
		 * @return string|null The COALESCE default ProcessExpression picks
		 */
		private function defaultFor(string $columnType, string $databaseType): ?string {
			/** @var EntityManager $entityManager */
			$entityManager = $GLOBALS['test_em'];
			$parameters = [];
			$visitor = new BuildSqlFromAst($entityManager->getEntityStore(), $parameters, 'SORT', new FakePlatformCapabilities($databaseType));

			/** @var ProcessExpression $expressionHandler */
			$expressionHandler = (new \ReflectionProperty(BuildSqlFromAst::class, 'expressionHandler'))->getValue($visitor);

			/** @var string|null $default */
			$default = (new \ReflectionMethod(ProcessExpression::class, 'sortDefaultForNull'))->invoke($expressionHandler, $columnType);
			return $default;
		}

		/**
		 * @return array<string, array{string, string, string|null}>
		 */
		public static function defaults(): array {
			return [
				'integer'             => ['integer', 'pgsql', '0'],
				'biginteger'          => ['biginteger', 'pgsql', '0'],
				'decimal'             => ['decimal', 'sqlsrv', '0'],
				'float'               => ['float', 'sqlsrv', '0'],
				'boolean on pgsql'    => ['boolean', 'pgsql', 'false'],
				'boolean on sqlsrv'   => ['boolean', 'sqlsrv', '0'],
				'datetime on pgsql'   => ['datetime', 'pgsql', "'0001-01-01'"],
				'timestamp on sqlsrv' => ['timestamp', 'sqlsrv', "'1753-01-01'"],
				'date on mysql'       => ['date', 'mysql', "'1000-01-01'"],
				'date on mariadb'     => ['date', 'mariadb', "'1000-01-01'"],
				'datetime on sqlite'  => ['datetime', 'sqlite', "'0001-01-01'"],
				'time'                => ['time', 'pgsql', "'00:00:00'"],
				'uuid'                => ['uuid', 'pgsql', "'00000000-0000-0000-0000-000000000000'"],
				'blob on sqlsrv'      => ['blob', 'sqlsrv', '0x'],
				'binary on pgsql'     => ['binary', 'pgsql', "''"],
				'string'              => ['string', 'pgsql', "''"],
				'enum'                => ['enum', 'sqlsrv', "''"],
				'json'                => ['json', 'pgsql', null],
			];
		}

		/**
		 * @param string $columnType Abstract column type
		 * @param string $databaseType Engine
		 * @param string|null $expected Expected default
		 * @return void
		 */
		#[DataProvider('defaults')]
		public function testDefault(string $columnType, string $databaseType, ?string $expected): void {
			self::assertSame($expected, $this->defaultFor($columnType, $databaseType));
		}
	}
