<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * Covers beginTrans()/commitTrans()/rollbackTrans()'s logical (depth-
	 * counted) nesting — in particular that a nested rollbackTrans() marks
	 * the transaction rollback-only rather than being silently overridden
	 * by an outer commitTrans(), which would otherwise persist a result a
	 * nested caller already asked to discard (see DdlRunner::runTransactionally(),
	 * whose whole point is a nested DDL failure never leaving a partial
	 * result behind).
	 */
	class DatabaseAdapterTransactionTest extends TestCase {
		use FkTestSupport;

		public function testCommitAfterMatchingBeginPersistsTheWrite(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE t (id INTEGER PRIMARY KEY)');

			$adapter->beginTrans();
			$adapter->execute('INSERT INTO t (id) VALUES (1)');
			$adapter->commitTrans();

			self::assertSame(1, (int)$adapter->execute('SELECT COUNT(*) AS c FROM t')->fetchAssoc()['c']);
		}

		public function testRollbackAfterMatchingBeginDiscardsTheWrite(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE t (id INTEGER PRIMARY KEY)');

			$adapter->beginTrans();
			$adapter->execute('INSERT INTO t (id) VALUES (1)');
			$adapter->rollbackTrans();

			self::assertSame(0, (int)$adapter->execute('SELECT COUNT(*) AS c FROM t')->fetchAssoc()['c']);
		}

		/**
		 * The scenario DdlRunner::runTransactionally() can hit when it's
		 * invoked while a caller already has a transaction open: the nested
		 * beginTrans()/rollbackTrans() pair only decrements the depth
		 * counter, so nothing physically rolls back yet — but the write it
		 * wanted to discard must not survive the outer commitTrans() either.
		 */
		public function testNestedRollbackIsHonoredEvenWhenTheOuterCallerCommits(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE t (id INTEGER PRIMARY KEY)');

			$adapter->beginTrans(); // outer
			$adapter->execute('INSERT INTO t (id) VALUES (1)');

			$adapter->beginTrans(); // nested, e.g. DdlRunner::runTransactionally()
			$adapter->execute('INSERT INTO t (id) VALUES (2)');
			$adapter->rollbackTrans(); // nested failure — depth-decrement only, no physical rollback yet

			try {
				$adapter->commitTrans(); // outer unwind — must not silently commit
				self::fail('Expected commitTrans() to throw after a nested rollback marked the transaction rollback-only');
			} catch (\LogicException $e) {
				self::assertStringContainsString('rollback-only', $e->getMessage());
			}

			// The whole transaction — including the outer INSERT — was
			// physically rolled back, not just the nested one.
			self::assertSame(0, (int)$adapter->execute('SELECT COUNT(*) AS c FROM t')->fetchAssoc()['c']);
		}

		public function testNestedCommitDoesNotPhysicallyCommitUntilTheOutermostUnwinds(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE t (id INTEGER PRIMARY KEY)');

			$adapter->beginTrans(); // outer
			$adapter->beginTrans(); // nested
			$adapter->execute('INSERT INTO t (id) VALUES (1)');
			$adapter->commitTrans(); // nested unwind — no physical commit yet
			$adapter->commitTrans(); // outer unwind — physical commit here

			self::assertSame(1, (int)$adapter->execute('SELECT COUNT(*) AS c FROM t')->fetchAssoc()['c']);
		}
	}
