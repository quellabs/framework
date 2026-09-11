<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Integration coverage for QUEL's `create [temporary] Name (...) [if not
	 * exists]` statement, exercised end-to-end via
	 * EntityManager::executeQuery() against the suite's shared MySQL
	 * connection. Verifies the resulting table via
	 * DatabaseAdapter::getColumns()/getPrimaryKey(), not just that no
	 * exception was thrown — the risk is in the generated DDL itself.
	 */
	class CreateTableTest extends TestCase {

		private static int $tableCounter = 0;

		/** @var string[] Tables created by the current test, dropped in tearDown() */
		private array $createdTables = [];

		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		protected function tearDown(): void {
			$connection = self::em()->getConnection();

			foreach ($this->createdTables as $tableName) {
				$connection->execute("DROP TABLE IF EXISTS `{$tableName}`");
			}

			$this->createdTables = [];
		}

		/**
		 * A unique table name per call, so tests never collide on a leftover
		 * table from a previous run in the same session.
		 */
		private function nextTableName(): string {
			return 'ct_test_' . getmypid() . '_' . (++self::$tableCounter);
		}

		public function testCreatesPermanentTableWithConstraints(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			$result = self::em()->executeQuery("
				create {$tableName} (
					id = integer identity,
					message = string(500),
					amount = decimal(10,2) nullable,
					created_at = datetime,
					primary key (id)
				)
			");

			// A DDL statement has no rows to return.
			$this->assertNull($result);

			$columns = self::em()->getConnection()->getColumns($tableName);

			$this->assertSame('integer', $columns['id']['type']);
			$this->assertTrue($columns['id']['identity']);
			$this->assertTrue($columns['id']['primary_key']);
			$this->assertFalse($columns['id']['nullable']);

			$this->assertSame('string', $columns['message']['type']);
			$this->assertSame(500, $columns['message']['limit']);
			$this->assertFalse($columns['message']['nullable']);

			$this->assertSame('decimal', $columns['amount']['type']);
			$this->assertSame(10, $columns['amount']['precision']);
			$this->assertSame(2, $columns['amount']['scale']);
			$this->assertTrue($columns['amount']['nullable']);

			$this->assertSame('datetime', $columns['created_at']['type']);
			$this->assertFalse($columns['created_at']['nullable']);

			$this->assertSame('id', self::em()->getConnection()->getPrimaryKey($tableName));
		}

		public function testCreatesTemporaryTableUsableInTheSameSession(): void {
			$tableName = $this->nextTableName();

			$result = self::em()->executeQuery("
				create temporary {$tableName} (
					user_id = integer,
					total = decimal
				)
			");

			$this->assertNull($result);

			// Session-scoped temp tables drop themselves when the connection
			// closes; explicitly drop it now so a leaked session doesn't leave
			// it behind for the rest of the suite.
			$connection = self::em()->getConnection();

			try {
				$connection->execute("INSERT INTO `{$tableName}` (user_id, total) VALUES (1, 9.5)");
				$stmt = $connection->execute("SELECT * FROM `{$tableName}`");
				$rows = $stmt->fetchAll('assoc');

				$this->assertCount(1, $rows);
				$this->assertEquals(1, $rows[0]['user_id']);
			} finally {
				$connection->execute("DROP TEMPORARY TABLE IF EXISTS `{$tableName}`");
			}
		}

		public function testRejectsMoreThanOnePrimaryKeyClause(): void {
			$tableName = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("
				create {$tableName} (
					a = integer,
					b = integer,
					primary key (a),
					primary key (b)
				)
			");
		}

		public function testRejectsIdentityWithoutPrimaryKey(): void {
			$tableName = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("
				create {$tableName} (
					a = integer identity
				)
			");
		}

		public function testRejectsIdentityColumnNotSolePrimaryKeyEntry(): void {
			$tableName = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("
				create {$tableName} (
					a = integer identity,
					b = integer,
					primary key (a, b)
				)
			");
		}

		public function testRejectsPrimaryKeyClauseOnUnknownColumn(): void {
			$tableName = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("
				create {$tableName} (
					a = integer,
					primary key (nope)
				)
			");
		}

		public function testCreatesTableWithCompositePrimaryKey(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			$result = self::em()->executeQuery("
				create {$tableName} (
					post_id = integer,
					tag_id = integer,
					primary key (post_id, tag_id)
				)
			");

			$this->assertNull($result);

			$columns = self::em()->getConnection()->getColumns($tableName);
			$this->assertFalse($columns['post_id']['nullable']);
			$this->assertFalse($columns['tag_id']['nullable']);
		}

		public function testIgnoresRangeDeclarationBeforeCreate(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			// Ranges are parsed once up front and shared across statements —
			// `create` just doesn't reference them, so this isn't an error.
			$result = self::em()->executeQuery("
				range of x is PostEntity
				create {$tableName} (id = integer)
			");

			$this->assertNull($result);
			$this->assertArrayHasKey('id', self::em()->getConnection()->getColumns($tableName));
		}

		public function testRejectsCreatingATableThatAlreadyExists(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			self::em()->executeQuery("create {$tableName} (id = integer)");

			// Unlike the parse-time rejections above, this is a real DB-level
			// failure — exercises CreateTableExecutor's null-return handling.
			$this->expectException(QuelException::class);

			self::em()->executeQuery("create {$tableName} (id = integer)");
		}

		public function testIfNotExistsCreatesTheTableWhenMissing(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			$result = self::em()->executeQuery("create {$tableName} (id = integer) if not exists");

			$this->assertNull($result);
			$this->assertArrayHasKey('id', self::em()->getConnection()->getColumns($tableName));
		}

		public function testIfNotExistsIsANoOpWhenTheTableAlreadyExists(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			self::em()->executeQuery("create {$tableName} (id = integer)");

			// Unlike testRejectsCreatingATableThatAlreadyExists, this must
			// succeed silently instead of throwing.
			$result = self::em()->executeQuery("create {$tableName} (id = integer) if not exists");

			$this->assertNull($result);
		}

		public function testRejectsUnknownColumnType(): void {
			$tableName = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("
				create {$tableName} (
					a = notareltype
				)
			");
		}

		public function testCreatesTableWithAnEmbeddedIndex(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			$result = self::em()->executeQuery("
				create {$tableName} (
					id = integer identity,
					tenant_id = integer,
					primary key (id),
					index idx_tenant (tenant_id)
				)
			");

			$this->assertNull($result);

			$indexes = self::em()->getConnection()->getIndexes($tableName);
			$this->assertArrayHasKey('idx_tenant', $indexes);
			$this->assertSame(['tenant_id'], $indexes['idx_tenant']['columns']);
		}

		public function testCreatesTableWithAnEmbeddedUniqueIndex(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			$result = self::em()->executeQuery("
				create {$tableName} (
					id = integer identity,
					email = string(100),
					primary key (id),
					unique index idx_email (email)
				)
			");

			$this->assertNull($result);

			$indexes = self::em()->getConnection()->getIndexes($tableName);
			$this->assertArrayHasKey('idx_email', $indexes);
			$this->assertSame('unique', $indexes['idx_email']['type']);
		}

		public function testCreatesTableWithAnEmbeddedFulltextIndex(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			$result = self::em()->executeQuery("
				create {$tableName} (
					id = integer identity,
					bio = string(500),
					primary key (id),
					fulltext index idx_bio (bio)
				)
			");

			$this->assertNull($result);

			$indexes = self::em()->getConnection()->getIndexes($tableName);
			$this->assertArrayHasKey('idx_bio', $indexes);
			$this->assertSame('fulltext', $indexes['idx_bio']['type']);
		}

		public function testCreatesTableWithACompositePrimaryKeyAndMultipleEmbeddedIndexes(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			// Mirrors the Bridge example in objectquel-index-clause-design.md.
			$result = self::em()->executeQuery("
				create {$tableName} (
					post_id = integer,
					tag_id = integer,
					created_at = datetime,
					primary key (post_id, tag_id),
					index idx_bridge_tag (tag_id),
					unique index idx_bridge_post_created (post_id, created_at)
				)
			");

			$this->assertNull($result);

			$indexes = self::em()->getConnection()->getIndexes($tableName);
			$this->assertArrayHasKey('idx_bridge_tag', $indexes);
			$this->assertSame(['tag_id'], $indexes['idx_bridge_tag']['columns']);

			$this->assertArrayHasKey('idx_bridge_post_created', $indexes);
			$this->assertSame('unique', $indexes['idx_bridge_post_created']['type']);
			$this->assertSame(['post_id', 'created_at'], $indexes['idx_bridge_post_created']['columns']);
		}

		public function testRejectsAnEmbeddedIndexOnAnUnknownColumn(): void {
			$tableName = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("
				create {$tableName} (
					id = integer,
					index idx_bad (nonexistent_column)
				)
			");
		}

		public function testRejectsTwoEmbeddedIndexesWithTheSameName(): void {
			$tableName = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("
				create {$tableName} (
					a = integer,
					b = integer,
					index idx_dup (a),
					index idx_dup (b)
				)
			");
		}

		/**
		 * ObjectQuel's `create` has no ENGINE clause, so the table this
		 * produces gets whatever MySQL's default_storage_engine is —
		 * MyISAM in this suite's test server, which silently accepts (and
		 * ignores) a FK constraint instead of erroring or storing it, the
		 * same reason DatabaseAdapterForeignKeyMySqlTest builds its own FK
		 * fixture tables with raw `... ENGINE=InnoDB` SQL rather than
		 * through any DSL. There's no such workaround available for
		 * `create`'s embedded entry itself (unlike AlterTableTest's
		 * add/drop foreign key coverage, which can pre-create its tables
		 * as InnoDB via raw SQL and still exercise real ObjectQuel `alter`
		 * DDL) — so this only verifies the generated SQL is accepted by a
		 * real server; exact rendering (including on delete/on update, and
		 * across all four dialects) is covered at the SQL-generation level
		 * by QuelToSQLCreateTest instead.
		 */
		public function testCreatesTableWithAnEmbeddedForeignKey(): void {
			$referencedTable = $this->nextTableName();
			$tableName = $this->nextTableName();

			self::em()->executeQuery("create {$referencedTable} (id = integer identity, primary key (id))");
			$this->createdTables[] = $tableName;
			$this->createdTables[] = $referencedTable;

			$result = self::em()->executeQuery("
				create {$tableName} (
					id = integer identity,
					author_id = integer,
					primary key (id),
					foreign key (author_id) references {$referencedTable} (id) on delete cascade
				)
			");

			$this->assertNull($result);
			$this->assertArrayHasKey('author_id', self::em()->getConnection()->getColumns($tableName));
		}

		public function testRejectsAnEmbeddedForeignKeyOnAnUnknownColumn(): void {
			$tableName = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("
				create {$tableName} (
					id = integer,
					foreign key (nonexistent_column) references OtherTable (id)
				)
			");
		}
	}
