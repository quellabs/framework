<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\DatabaseAdapter;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\MysqlSchemaIntrospector;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\PostgresSchemaIntrospector;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\SchemaIntrospectorInterface;

	/**
	 * Table names in the index usage query are quoted for the engine.
	 */
	class IndexUsageStatisticsQuotingTest extends TestCase {

		/**
		 * @return array<string, array{string, class-string<SchemaIntrospectorInterface>, string}>
		 */
		public static function introspectors(): array {
			return [
				'mysql' => ['mysql', MysqlSchemaIntrospector::class, "IN ('plain', 'a''b\\\\c')"],
				'pgsql' => ['pgsql', PostgresSchemaIntrospector::class, "IN ('plain', 'a''b\\c')"],
			];
		}

		/**
		 * @param string $databaseType Engine the adapter reports
		 * @param class-string<SchemaIntrospectorInterface> $introspectorClass Introspector under test
		 * @param string $expected The IN list in the executed query
		 * @return void
		 */
		#[DataProvider('introspectors')]
		public function testTableNamesAreQuotedForTheEngine(string $databaseType, string $introspectorClass, string $expected): void {
			$query = null;
			$adapter = $this->createStub(DatabaseAdapter::class);
			$adapter->method('getDatabaseType')->willReturn($databaseType);
			$adapter->method('execute')->willReturnCallback(function (string $sql) use (&$query) {
				$query = $sql;
				return null;
			});

			self::assertNull((new $introspectorClass($adapter))->getIndexUsageStatistics(['plain', 'a\'b\\c']));
			self::assertIsString($query);
			self::assertStringContainsString($expected, $query);
		}
	}
