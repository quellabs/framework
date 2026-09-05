<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Integration coverage for QUEL's `range of x is table Name` plain-table
	 * range — a range that targets a physical table directly, with no backing
	 * entity class (see objectquel-plain-table-range-plan.md). Exercised
	 * end-to-end via EntityManager::executeQuery()/executeQuery() against
	 * the suite's shared MySQL connection, on both an ad hoc `create`-d table
	 * and a table that was never touched by ObjectQuel DDL at all — the
	 * plan's core claim is that neither case needs an entity class to be
	 * queried or written to.
	 */
	class PlainTableRangeTest extends TestCase {

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

		private function nextTableName(): string {
			return 'ptr_test_' . getmypid() . '_' . (++self::$tableCounter);
		}

		/**
		 * Creates a plain table directly via raw SQL (bypassing QUEL's own
		 * `create` entirely) so this suite also covers a genuinely
		 * pre-existing table, not only one `create`-d through ObjectQuel.
		 */
		private function createRawTable(string $tableName): void {
			$this->createdTables[] = $tableName;

			self::em()->getConnection()->execute("
				CREATE TABLE `{$tableName}` (
					id INT AUTO_INCREMENT PRIMARY KEY,
					message VARCHAR(255) NOT NULL,
					amount INT NOT NULL DEFAULT 0
				)
			");
		}

		public function testRetrievesColumnsFromAPreExistingPlainTable(): void {
			$tableName = $this->nextTableName();
			$this->createRawTable($tableName);

			self::em()->getConnection()->execute(
				"INSERT INTO `{$tableName}` (message, amount) VALUES (?, ?)",
				['hello', 42]
			);

			$rows = self::em()->getAll("
				range of a is table {$tableName}
				retrieve (a.message, a.amount) where a.amount = :amount
			", ['amount' => 42]);

			$this->assertCount(1, $rows);
			$this->assertSame('hello', $rows[0]['a.message']);
			$this->assertSame(42, (int)$rows[0]['a.amount']);
		}

		public function testWorksOnATableCreatedThroughQuel(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			self::em()->executeQuery("
				create {$tableName} (
					id = integer identity primary key,
					message = string(255) not null
				)
			");

			self::em()->executeQuery("
				range of a is table {$tableName}
				append to a (message = :message)
			", ['message' => 'created-by-quel']);

			$messages = self::em()->getCol("range of a is table {$tableName} retrieve (a.message)");
			$this->assertSame(['created-by-quel'], $messages);
		}

		public function testAppendsToAPlainTable(): void {
			$tableName = $this->nextTableName();
			$this->createRawTable($tableName);

			$result = self::em()->executeQuery("
				range of a is table {$tableName}
				append to a (message = :message, amount = :amount)
			", ['message' => 'inserted', 'amount' => 7]);

			$this->assertSame(1, $result->getAffectedRows());
			// No entity metadata to know about the auto-increment column, but
			// getInsertId() readback is still attempted (see
			// objectquel-plain-table-range-plan.md's "Open decisions").
			$this->assertIsInt($result->getGeneratedId());

			$rows = self::em()->getAll("range of a is table {$tableName} retrieve (a.message, a.amount)");
			$this->assertCount(1, $rows);
			$this->assertSame('inserted', $rows[0]['a.message']);
			$this->assertSame(7, (int)$rows[0]['a.amount']);
		}

		public function testReplaceUpdatesAPlainTable(): void {
			$tableName = $this->nextTableName();
			$this->createRawTable($tableName);

			self::em()->getConnection()->execute(
				"INSERT INTO `{$tableName}` (message, amount) VALUES (?, ?)",
				['old', 1]
			);

			$result = self::em()->executeQuery("
				range of a is table {$tableName}
				replace a (message = :message) where a.amount = :amount
			", ['message' => 'new', 'amount' => 1]);

			$this->assertSame(1, $result->getAffectedRows());

			$messages = self::em()->getCol("range of a is table {$tableName} retrieve (a.message)");
			$this->assertSame(['new'], $messages);
		}

		public function testDeleteRemovesFromAPlainTable(): void {
			$tableName = $this->nextTableName();
			$this->createRawTable($tableName);

			self::em()->getConnection()->execute(
				"INSERT INTO `{$tableName}` (message, amount) VALUES (?, ?)",
				['gone', 1]
			);

			$result = self::em()->executeQuery("
				range of a is table {$tableName}
				delete a where a.amount = :amount
			", ['amount' => 1]);

			$this->assertSame(1, $result->getAffectedRows());

			$rows = self::em()->getAll("range of a is table {$tableName} retrieve (a.message)");
			$this->assertCount(0, $rows);
		}

		public function testReplaceOnAPlainTableAcceptsABareUnqualifiedColumn(): void {
			$tableName = $this->nextTableName();
			$this->createRawTable($tableName);

			self::em()->getConnection()->execute(
				"INSERT INTO `{$tableName}` (message, amount) VALUES (?, ?)",
				['old', 1]
			);

			// `amount` instead of `a.amount` in the WHERE clause — must resolve
			// against the table range the same way a retrieve's WHERE clause does.
			$result = self::em()->executeQuery("
				range of a is table {$tableName}
				replace a (message = :message) where amount = :amount
			", ['message' => 'new', 'amount' => 1]);

			$this->assertSame(1, $result->getAffectedRows());

			$messages = self::em()->getCol("range of a is table {$tableName} retrieve (a.message)");
			$this->assertSame(['new'], $messages);
		}

		public function testDeleteOnAPlainTableAcceptsABareUnqualifiedColumn(): void {
			$tableName = $this->nextTableName();
			$this->createRawTable($tableName);

			self::em()->getConnection()->execute(
				"INSERT INTO `{$tableName}` (message, amount) VALUES (?, ?)",
				['gone', 1]
			);

			// `amount` instead of `a.amount` in the WHERE clause — must resolve
			// against the table range the same way a retrieve's WHERE clause does.
			$result = self::em()->executeQuery("
				range of a is table {$tableName}
				delete a where amount = :amount
			", ['amount' => 1]);

			$this->assertSame(1, $result->getAffectedRows());

			$rows = self::em()->getAll("range of a is table {$tableName} retrieve (a.message)");
			$this->assertCount(0, $rows);
		}

		public function testResolvesBareColumnAgainstASingleTableRange(): void {
			$tableName = $this->nextTableName();
			$this->createRawTable($tableName);

			self::em()->getConnection()->execute(
				"INSERT INTO `{$tableName}` (message, amount) VALUES (?, ?)",
				['bare-column', 42]
			);

			// Bare `message`/`amount` (no `a.` prefix) must resolve against the
			// single table range the same way it already does for a single
			// entity range — this mirrors ResolveUnqualifiedProperty's shorthand
			// support, which previously only checked AstRangeDatabase ranges.
			$rows = self::em()->getAll("
				range of a is table {$tableName}
				retrieve (message, amount) where amount = :amount
			", ['amount' => 42]);

			$this->assertCount(1, $rows);
			$this->assertSame('bare-column', $rows[0]['message']);
			$this->assertSame(42, (int)$rows[0]['amount']);
		}

		public function testRejectsBareRangeProjection(): void {
			$tableName = $this->nextTableName();
			$this->createRawTable($tableName);

			$this->expectException(QuelException::class);

			self::em()->getAll("range of a is table {$tableName} retrieve (a)");
		}

		public function testUnknownColumnSurfacesAsANativeDatabaseError(): void {
			$tableName = $this->nextTableName();
			$this->createRawTable($tableName);

			// No live-schema validation for a plain-table range (see
			// objectquel-plain-table-range-plan.md) — an unknown column is
			// rejected by the database itself, not by the compiler.
			$this->expectException(QuelException::class);

			self::em()->getAll("range of a is table {$tableName} retrieve (a.does_not_exist)");
		}

		public function testAggregatesOverAPlainTable(): void {
			$tableName = $this->nextTableName();
			$this->createRawTable($tableName);

			self::em()->getConnection()->execute(
				"INSERT INTO `{$tableName}` (message, amount) VALUES (?, ?), (?, ?)",
				['a', 3, 'b', 4]
			);

			$total = self::em()->getCol("range of a is table {$tableName} retrieve (total = sum(a.amount))");

			$this->assertSame([7], array_map('intval', $total));
		}

		public function testUpsertWorksOnAPlainTableRange(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			// A real unique constraint on `email` — required for the compiled
			// ON DUPLICATE KEY UPDATE to be atomic; no declared-constraint
			// pre-check runs for a plain-table range (see
			// objectquel-plain-table-range-plan.md), so if this weren't a
			// real constraint the database itself would reject the statement.
			self::em()->getConnection()->execute("
				CREATE TABLE `{$tableName}` (
					id INT AUTO_INCREMENT PRIMARY KEY,
					email VARCHAR(191) NOT NULL,
					name VARCHAR(255) NOT NULL,
					UNIQUE KEY uniq_email (email)
				)
			");

			$insert = self::em()->executeQuery("
				range of a is table {$tableName}
				append to a (email = :e, name = :n) or replace (name = :n) where a.email = :e
			", ['e' => 'alice@example.com', 'n' => 'Alice']);

			$this->assertSame(1, $insert->getAffectedRows());

			self::em()->executeQuery("
				range of a is table {$tableName}
				append to a (email = :e, name = :n) or replace (name = :n) where a.email = :e
			", ['e' => 'alice@example.com', 'n' => 'Alice V2']);

			$names = self::em()->getCol("range of a is table {$tableName} retrieve (a.name)");
			$this->assertSame(['Alice V2'], $names);
		}

		public function testJoinsAPlainTableWithAnEntityRangeViaWhere(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			self::em()->getConnection()->execute("
				CREATE TABLE `{$tableName}` (
					id INT AUTO_INCREMENT PRIMARY KEY,
					user_id INT NOT NULL,
					note VARCHAR(255) NOT NULL
				)
			");

			$appendResult = self::em()->executeQuery(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'joinee', 'password' => 'secret']
			);

			$userId = $appendResult->getGeneratedId();

			self::em()->getConnection()->execute(
				"INSERT INTO `{$tableName}` (user_id, note) VALUES (?, ?)",
				[$userId, 'a note']
			);

			$rows = self::em()->getAll("
				range of u is App\\Entities\\UserEntity
				range of n is table {$tableName}
				retrieve (u.username, n.note) where u.id = n.user_id and u.id = :id
			", ['id' => $userId]);

			$this->assertCount(1, $rows);
			$this->assertSame('joinee', $rows[0]['u.username']);
			$this->assertSame('a note', $rows[0]['n.note']);

			self::em()->getConnection()->execute("DELETE FROM `users` WHERE id = ?", [$userId]);
		}

		public function testViaOnAPlainTableRangeGeneratesARealLeftJoin(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			self::em()->getConnection()->execute("
				CREATE TABLE `{$tableName}` (
					id INT AUTO_INCREMENT PRIMARY KEY,
					user_id INT NOT NULL,
					note VARCHAR(255) NOT NULL
				)
			");

			$withNote = self::em()->executeQuery(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'has-note', 'password' => 'secret']
			);
			$withoutNote = self::em()->executeQuery(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'no-note', 'password' => 'secret']
			);

			self::em()->getConnection()->execute(
				"INSERT INTO `{$tableName}` (user_id, note) VALUES (?, ?)",
				[$withNote->getGeneratedId(), 'hello']
			);

			// `via` on a plain-table range takes the literal join condition
			// directly (no relation to name) and is always a LEFT JOIN, so
			// no-note's row still appears with a null n.note.
			$rows = self::em()->getAll("
				range of u is App\\Entities\\UserEntity
				range of n is table {$tableName} via u.id = n.user_id
				retrieve (u.username, n.note)
				where u.id = :withNoteId or u.id = :withoutNoteId
				sort by u.username asc
			", ['withNoteId' => $withNote->getGeneratedId(), 'withoutNoteId' => $withoutNote->getGeneratedId()]);

			$this->assertCount(2, $rows);
			$this->assertSame('has-note', $rows[0]['u.username']);
			$this->assertSame('hello', $rows[0]['n.note']);
			$this->assertSame('no-note', $rows[1]['u.username']);
			$this->assertNull($rows[1]['n.note']);

			self::em()->getConnection()->execute(
				"DELETE FROM `users` WHERE id IN (?, ?)",
				[$withNote->getGeneratedId(), $withoutNote->getGeneratedId()]
			);
		}

		public function testViaJoinsAPlainTableRangeToAnEntityRangeOnANonKeyProperty(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			// Collation must match `users`.`username` (utf8mb4_unicode_ci) — the
			// server/schema default here is utf8mb4_general_ci, and joining
			// mismatched collations without an explicit CAST is a MySQL error
			// (1267), not something this feature papers over.
			self::em()->getConnection()->execute("
				CREATE TABLE `{$tableName}` (
					id INT AUTO_INCREMENT PRIMARY KEY,
					owner_username VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
					note VARCHAR(255) NOT NULL
				)
			");

			$withNote = self::em()->executeQuery(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'via-owner', 'password' => 'secret']
			);
			$withoutNote = self::em()->executeQuery(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'via-no-owner', 'password' => 'secret']
			);

			self::em()->getConnection()->execute(
				"INSERT INTO `{$tableName}` (owner_username, note) VALUES (?, ?)",
				['via-owner', 'owned note']
			);

			// The table range's `via` condition mixes an entity-rooted identifier
			// (u.username, resolved through UserEntity's property-to-column
			// metadata) with a table-rooted one (n.owner_username, passed through
			// as a literal column name) — confirms the two sides of a mixed
			// table/entity join are resolved independently, on a non-key entity
			// property rather than the join-column id used elsewhere.
			$rows = self::em()->getAll("
				range of u is App\\Entities\\UserEntity
				range of n is table {$tableName} via u.username = n.owner_username
				retrieve (u.username, n.note)
				where u.id = :withNoteId or u.id = :withoutNoteId
				sort by u.username asc
			", ['withNoteId' => $withNote->getGeneratedId(), 'withoutNoteId' => $withoutNote->getGeneratedId()]);

			$this->assertCount(2, $rows);
			$this->assertSame('via-no-owner', $rows[0]['u.username']);
			$this->assertNull($rows[0]['n.note']);
			$this->assertSame('via-owner', $rows[1]['u.username']);
			$this->assertSame('owned note', $rows[1]['n.note']);

			self::em()->getConnection()->execute(
				"DELETE FROM `users` WHERE id IN (?, ?)",
				[$withNote->getGeneratedId(), $withoutNote->getGeneratedId()]
			);
		}

		public function testViaJoiningTwoPlainTableRanges(): void {
			$ordersTable = $this->nextTableName();
			$itemsTable = $this->nextTableName();
			$this->createdTables[] = $ordersTable;
			$this->createdTables[] = $itemsTable;

			self::em()->getConnection()->execute("
				CREATE TABLE `{$ordersTable}` (
					id INT AUTO_INCREMENT PRIMARY KEY,
					label VARCHAR(255) NOT NULL
				)
			");
			self::em()->getConnection()->execute("
				CREATE TABLE `{$itemsTable}` (
					id INT AUTO_INCREMENT PRIMARY KEY,
					order_id INT NOT NULL,
					sku VARCHAR(255) NOT NULL
				)
			");

			self::em()->getConnection()->execute("INSERT INTO `{$ordersTable}` (label) VALUES ('order-1')");
			$orderId = (int)self::em()->getConnection()->getInsertId();
			self::em()->getConnection()->execute(
				"INSERT INTO `{$itemsTable}` (order_id, sku) VALUES (?, ?)",
				[$orderId, 'sku-1']
			);

			$rows = self::em()->getAll("
				range of o is table {$ordersTable}
				range of i is table {$itemsTable} via o.id = i.order_id
				retrieve (o.label, i.sku)
			");

			$this->assertCount(1, $rows);
			$this->assertSame('order-1', $rows[0]['o.label']);
			$this->assertSame('sku-1', $rows[0]['i.sku']);
		}

		public function testResolvesBareColumnAgainstOneOfTwoJoinedTableRanges(): void {
			$customersTable = $this->nextTableName();
			$addressesTable = $this->nextTableName();
			$this->createdTables[] = $customersTable;
			$this->createdTables[] = $addressesTable;

			self::em()->getConnection()->execute("
				CREATE TABLE `{$customersTable}` (
					id INT AUTO_INCREMENT PRIMARY KEY,
					name VARCHAR(255) NOT NULL
				)
			");
			self::em()->getConnection()->execute("
				CREATE TABLE `{$addressesTable}` (
					id INT AUTO_INCREMENT PRIMARY KEY,
					customer_id INT NOT NULL,
					street VARCHAR(255) NOT NULL,
					house_number VARCHAR(255) NOT NULL
				)
			");

			self::em()->getConnection()->execute("INSERT INTO `{$customersTable}` (name) VALUES ('Jane')");
			$customerId = (int)self::em()->getConnection()->getInsertId();
			self::em()->getConnection()->execute(
				"INSERT INTO `{$addressesTable}` (customer_id, street, house_number) VALUES (?, ?, ?)",
				[$customerId, 'Main St', '12']
			);

			// `street`/`house_number` only exist on the addresses table, not
			// customers, so referencing them unqualified must resolve against
			// the addresses range rather than being rejected as ambiguous —
			// findRanges() now checks each plain table's real columns instead
			// of blindly matching every table range in the query.
			$rows = self::em()->getAll("
				range of c is table {$customersTable}
				range of a is table {$addressesTable} via a.customer_id = c.id
				retrieve unique (street, house_number)
				where not is_null(customer_id)
			");

			$this->assertCount(1, $rows);
			$this->assertSame('Main St', $rows[0]['street']);
			$this->assertSame('12', $rows[0]['house_number']);
		}
	}
