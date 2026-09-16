<?php

	namespace Quellabs\ObjectQuel\Migration;

	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Tracks which migrations have been applied, in a dedicated table
	 * (default name `quel_migrations`, see Configuration::getMigrationTable()).
	 *
	 * The table itself is created via ObjectQuel DDL (`create ...`), through
	 * EntityManager::executeQuery() — `create`/`alter`/`destroy` are schema
	 * statements that name a table directly and need no declared range.
	 * Reading/writing its rows is different: every ObjectQuel DML verb
	 * (`append`/`retrieve`/`delete`) requires a declared `range of x is
	 * <Entity>`, which in turn requires a real, annotated entity class the
	 * application's EntityStore can resolve — not something this internal
	 * bookkeeping table has or needs. Row access therefore goes through the
	 * underlying CakePHP connection's parameterized query builder
	 * (insertQuery()/selectQuery()/deleteQuery()) instead — structured,
	 * bound-parameter queries, not hand-written SQL text.
	 *
	 * `version` is never DB-generated (no identity/auto-increment): it's
	 * always the calling migration's own `<YmdHis>` filename timestamp,
	 * supplied explicitly by the caller of recordApplied().
	 */
	class MigrationRepository {

		public function __construct(
			private readonly EntityManager $entityManager,
			private readonly string $migrationTable = 'quel_migrations',
		) {
		}

		/**
		 * Creates the tracking table if it doesn't already exist. Safe to
		 * call on every run — no-ops once the table is there.
		 * @return void
		 */
		public function ensureTableExists(): void {
			if (in_array($this->migrationTable, $this->entityManager->getConnection()->getTables(), true)) {
				return;
			}

			$this->entityManager->executeQuery(
				"create {$this->migrationTable} (" .
				"version = biginteger, " .
				"migration_name = string(255), " .
				"executed_at = datetime, " .
				"primary key (version))"
			);
		}

		/**
		 * Returns every applied migration's version, ascending.
		 * @return int[]
		 */
		public function getAppliedVersions(): array {
			$rows = $this->entityManager->getConnection()->getConnection()
				->selectQuery('version', $this->migrationTable)
				->orderBy('version')
				->execute()
				->fetchAll('assoc');

			return array_map(static fn(array $row): int => (int)$row['version'], $rows);
		}

		/**
		 * Records a migration as applied.
		 * @param int $version The migration's own <YmdHis> filename timestamp
		 * @param string $name The migration's class name
		 * @return void
		 */
		public function recordApplied(int $version, string $name): void {
			$this->entityManager->getConnection()->getConnection()
				->insertQuery($this->migrationTable, [
					'version'        => $version,
					'migration_name' => $name,
					'executed_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
				])
				->execute();
		}

		/**
		 * Removes a migration's applied record, undoing recordApplied().
		 * @param int $version
		 * @return void
		 */
		public function recordReverted(int $version): void {
			$this->entityManager->getConnection()->getConnection()
				->deleteQuery($this->migrationTable, ['version' => $version])
				->execute();
		}
	}
