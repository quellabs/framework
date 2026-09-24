<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Execution\Helpers\RoutineCatalog;

	/**
	 * Mapping a function's catalog return type to an abstract column type.
	 */
	class RoutineCatalogTest extends TestCase {

		/**
		 * @return array<string, array{string, string, string|null, int|null, string|null}>
		 */
		public static function returnTypes(): array {
			return [
				'mysql int'                 => ['mysql', 'int', 'int', null, 'integer'],
				'mysql tinyint(1)'          => ['mysql', 'tinyint', 'tinyint(1)', null, 'boolean'],
				'mariadb datetime'          => ['mariadb', 'datetime', 'datetime', null, 'datetime'],
				'mysql char(36)'            => ['mysql', 'char', 'char(36)', 36, 'uuid'],
				'mysql enum'                => ['mysql', 'enum', "enum('a','b')", 1, 'string'],
				'pgsql timestamp'           => ['pgsql', 'timestamp without time zone', null, null, 'datetime'],
				'pgsql boolean'             => ['pgsql', 'boolean', null, null, 'boolean'],
				'sqlsrv nvarchar(max)'      => ['sqlsrv', 'nvarchar', null, -1, 'text'],
				'sqlsrv datetime2'          => ['sqlsrv', 'datetime2', null, 8, 'datetime'],
				'sqlsrv legacy datetime'    => ['sqlsrv', 'datetime', null, 8, null],
				'pgsql type ObjectQuel lacks' => ['pgsql', 'money', null, null, null],
			];
		}

		/**
		 * @param string $databaseType Engine
		 * @param string $dataType Catalog type name
		 * @param string|null $typeDetail MySQL DTD_IDENTIFIER
		 * @param int|null $maxLength Catalog length
		 * @param string|null $expected Expected abstract type
		 * @return void
		 */
		#[DataProvider('returnTypes')]
		public function testReturnType(string $databaseType, string $dataType, ?string $typeDetail, ?int $maxLength, ?string $expected): void {
			self::assertSame($expected, RoutineCatalog::returnType($databaseType, $dataType, $typeDetail, $maxLength));
		}
	}
