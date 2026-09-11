<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;
	use Quellabs\SignalHub\SignalHubLocator;
	use Quellabs\SignalHub\Slot;

	/**
	 * Integration coverage for @Orm\Version handling on `append` and
	 * `replace`, exercised end-to-end via EntityManager::executeQuery()
	 * against the suite's shared MySQL connection.
	 *
	 * Uses App\Entities\VersionedEntity ("versioned_entities": id identity
	 * PK, label, version int @Orm\Version) — the only fixture entity with a
	 * version column, backed by a table created ad hoc in tests/bootstrap.php.
	 *
	 * `replace`'s version bump itself (buildVersionSetClause()'s per-type
	 * logic — integer/datetime/uuid) is unit-tested in isolation by
	 * tests/Unit/Persistence/VersionValueHandlerTest.php; this class instead
	 * proves the wiring actually fires end-to-end through real `append`/
	 * `replace` statements.
	 */
	class WriteVerbVersionColumnTest extends ObjectQuelTestCase {

		protected array $truncateTables = ['posts', 'users', 'versioned_entities', 'uuid_versioned_entities'];

		public function testAppendInitializesAnUnsuppliedVersionColumnToOne(): void {
			$result = $this->em->executeQuery('
				range of v is App\Entities\VersionedEntity
				append to v (label = :label)
			', ['label' => 'first']);

			$this->assertSame(1, $result->getAffectedRows());
			$id = $result->getGeneratedId();

			$version = $this->em->getCol('
				range of v is App\Entities\VersionedEntity retrieve (v.version) where v.id = :id
			', ['id' => $id]);

			$this->assertSame([1], $version);
		}

		public function testAppendRespectsAnExplicitlySuppliedVersionValue(): void {
			$result = $this->em->executeQuery('
				range of v is App\Entities\VersionedEntity
				append to v (label = :label, version = :version)
			', ['label' => 'seeded', 'version' => 42]);

			$id = $result->getGeneratedId();

			$version = $this->em->getCol('
				range of v is App\Entities\VersionedEntity retrieve (v.version) where v.id = :id
			', ['id' => $id]);

			$this->assertSame([42], $version);
		}

		public function testAppendInitializesTheVersionColumnOnEveryRowOfAMultiRowInsert(): void {
			$this->em->executeQuery('
				range of v is App\Entities\VersionedEntity
				append to v (label = "a"), (label = "b")
			');

			$versions = $this->em->getCol('
				range of v is App\Entities\VersionedEntity retrieve (v.version) sort by v.label asc
			');

			$this->assertSame([1, 1], $versions);
		}

		public function testReplaceBumpsTheVersionColumnEvenWhenNotExplicitlyAssigned(): void {
			$seeded = $this->em->executeQuery('
				range of v is App\Entities\VersionedEntity
				append to v (label = :label)
			', ['label' => 'to-update']);

			$id = $seeded->getGeneratedId();

			$this->em->executeQuery('
				range of v is App\Entities\VersionedEntity
				replace v (label = :label) where v.id = :id
			', ['label' => 'updated', 'id' => $id]);

			$row = $this->em->getAll('
				range of v is App\Entities\VersionedEntity retrieve (v.label, v.version) where v.id = :id
			', ['id' => $id]);

			$this->assertSame('updated', $row[0]['v.label']);
			// Started at 1 (append's auto-init), replace bumps it to 2.
			$this->assertSame(2, $row[0]['v.version']);
		}

		public function testAppendInitializesAUuidVersionColumnToAFreshValue(): void {
			$result = $this->em->executeQuery('
				range of u is App\Entities\UuidVersionedEntity
				append to u (label = :label)
			', ['label' => 'first']);

			$id = $result->getGeneratedId();

			$token = $this->em->getCol('
				range of u is App\Entities\UuidVersionedEntity retrieve (u.token) where u.id = :id
			', ['id' => $id]);

			$this->assertNotSame('', $token[0]);
		}

		/**
		 * Regression test: bumping a uuid-typed @Orm\Version column on
		 * `replace` requires adding a brand-new bound parameter to the
		 * statement (unlike an integer version's `col = col + 1`, which
		 * needs none) — see VersionValueHandler::buildVersionSetClause()'s
		 * uuid branch. That added parameter used to be silently dropped
		 * before execution because ReplaceExecutor::compileSql() took
		 * $parameters by value instead of by reference, discarding
		 * convertToSQL()'s mutation once it returned just the SQL string.
		 */
		public function testReplaceBumpsAUuidVersionColumnToAFreshValue(): void {
			$seeded = $this->em->executeQuery('
				range of u is App\Entities\UuidVersionedEntity
				append to u (label = :label)
			', ['label' => 'to-update']);

			$id = $seeded->getGeneratedId();

			$before = $this->em->getCol('
				range of u is App\Entities\UuidVersionedEntity retrieve (u.token) where u.id = :id
			', ['id' => $id]);

			$this->em->executeQuery('
				range of u is App\Entities\UuidVersionedEntity
				replace u (label = :label) where u.id = :id
			', ['label' => 'updated', 'id' => $id]);

			$after = $this->em->getCol('
				range of u is App\Entities\UuidVersionedEntity retrieve (u.token) where u.id = :id
			', ['id' => $id]);

			$this->assertNotSame('', $after[0]);
			$this->assertNotSame($before[0], $after[0]);
		}

		/**
		 * Regression test: `replace`'s debug signal used to recompile the
		 * statement to show its SQL, which for a uuid/guid @Orm\Version column
		 * not explicitly assigned bumps the column to a second, different
		 * value than what real execution already persisted (see
		 * VersionValueHandler::buildVersionSetClause()). The debug signal now
		 * shows no SQL for any write-verb statement at all — see
		 * QueryExecutor::explainQuery()'s docblock — which sidesteps this case
		 * (and the equivalent one for an integer version bump, which was safe
		 * to recompile but is no longer shown either) by construction, rather
		 * than by detecting it. The standalone EntityManager::explainQuery()
		 * API (nothing has executed yet there) still shows real SQL for
		 * `replace`, uuid version bump included — see ExplainQueryTest.
		 */
		public function testReplaceDebugSignalShowsNoSqlWhenItWouldBumpAUuidVersionColumnAgain(): void {
			$seeded = $this->em->executeQuery('
				range of u is App\Entities\UuidVersionedEntity
				append to u (label = :label)
			', ['label' => 'to-update']);

			$id = $seeded->getGeneratedId();
			$captured = $this->captureDebugSignalFor(function () use ($id) {
				return $this->em->executeQuery('
					range of u is App\Entities\UuidVersionedEntity
					replace u (label = :label) where u.id = :id
				', ['label' => 'updated', 'id' => $id]);
			});

			$this->assertSame([], $captured['query_plan']->getSql());
		}

		/**
		 * Enables development mode, runs $callback, and returns the payload
		 * captured off the 'debug.database.query' signal — shared boilerplate
		 * for the two debug-signal regression tests above.
		 * @param callable(): mixed $callback
		 * @return array<string, mixed>
		 */
		private function captureDebugSignalFor(callable $callback): array {
			$configProperty = new \ReflectionProperty($this->em, 'configuration');
			$configProperty->setAccessible(true);
			$configuration = $configProperty->getValue($this->em);
			$configuration->setDevelopmentMode(true);

			$signal = SignalHubLocator::getInstance()->getSignal('debug.database.query');
			$captured = null;
			$slot = new Slot(function (array $payload) use (&$captured): void {
				$captured = $payload;
			});
			$signal->connect($slot);

			try {
				$callback();
				$this->assertNotNull($captured);
				return $captured;
			} finally {
				$signal->disconnect($slot);
				$configuration->setDevelopmentMode(false);
			}
		}
	}
