<?php

	namespace Quellabs\ObjectQuel\Migration;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Drives migration discovery and execution — the replacement for
	 * Phinx's Manager inside QuelMigrateCommand (see Phase 3 of
	 * objectquel-migrations-implementation-plan.md).
	 *
	 * Each migration's up()/down() runs wrapped in its own transaction when
	 * PlatformCapabilitiesInterface::supportsTransactionalDDL() is true
	 * (PostgreSQL, SQLite, SQL Server); otherwise best-effort (MySQL/
	 * MariaDB, whose DDL auto-commits per statement regardless). Either
	 * way, a failing migration stops the run — later pending/applied
	 * migrations in the same migrate()/rollback() call are never touched,
	 * mirroring DdlRunner::runTransactionally()'s semantics elsewhere in
	 * this codebase, though mirrored rather than reused directly: DdlRunner
	 * wraps a flat list of SQL statements, not an arbitrary migration
	 * callable.
	 */
	class MigrationRunner {

		public function __construct(
			private readonly EntityManager $entityManager,
			private readonly PlatformCapabilitiesInterface $platform,
			private readonly MigrationRepository $repository,
			private readonly MigrationLocator $locator,
		) {
		}

		/**
		 * Every located migration not yet recorded as applied, ascending by
		 * version.
		 * @return list<LocatedMigration>
		 */
		public function getPending(): array {
			$this->repository->ensureTableExists();

			$applied = array_flip($this->repository->getAppliedVersions());

			return array_values(array_filter(
				$this->locator->locate(),
				static fn(LocatedMigration $migration) => !isset($applied[$migration->version])
			));
		}

		/**
		 * Applies every pending migration, ascending by version. With
		 * $target given, stops after applying the migration whose version
		 * equals $target (inclusive) — later pending migrations are left
		 * untouched.
		 * @param int|null $target
		 * @return void
		 * @throws \Throwable Propagated from a failing migration's up(), after rollback where the platform supports it
		 */
		public function migrate(?int $target = null): void {
			foreach ($this->getPending() as $migration) {
				if ($target !== null && $migration->version > $target) {
					break;
				}

				$this->runTransactionally(static fn() => $migration->migration->up());
				$this->repository->recordApplied($migration->version, $migration->name);
			}
		}

		/**
		 * Reverts applied migrations, most-recent-first. With $target
		 * given, reverts every applied migration whose version is greater
		 * than $target (exclusive — $target itself stays applied);
		 * otherwise reverts the $steps most recently applied migrations.
		 * @param int|null $target
		 * @param int $steps Only consulted when $target is null
		 * @return void
		 * @throws \Throwable Propagated from a failing migration's down(), after rollback where the platform supports it
		 */
		public function rollback(?int $target = null, int $steps = 1): void {
			$this->repository->ensureTableExists();

			$appliedVersions = array_flip($this->repository->getAppliedVersions());

			$applied = array_values(array_filter(
				$this->locator->locate(),
				static fn(LocatedMigration $migration) => isset($appliedVersions[$migration->version])
			));

			usort($applied, static fn(LocatedMigration $a, LocatedMigration $b) => $b->version <=> $a->version);

			$toRevert = $target !== null
				? array_filter($applied, static fn(LocatedMigration $migration) => $migration->version > $target)
				: array_slice($applied, 0, $steps);

			foreach ($toRevert as $migration) {
				$this->runTransactionally(static fn() => $migration->migration->down());
				$this->repository->recordReverted($migration->version);
			}
		}

		/**
		 * Every located migration, ascending by version, paired with
		 * whether (and when) it's been applied.
		 * @return list<array{version: int, name: string, applied_at: string|null}>
		 */
		public function status(): array {
			$this->repository->ensureTableExists();

			$appliedRecords = $this->repository->getAppliedRecords();

			return array_map(
				static fn(LocatedMigration $migration) => [
					'version'    => $migration->version,
					'name'       => $migration->name,
					'applied_at' => $appliedRecords[$migration->version] ?? null,
				],
				$this->locator->locate()
			);
		}

		/**
		 * Runs $callback wrapped in a transaction when the platform
		 * supports transactional DDL, rolling back and rethrowing on
		 * failure; otherwise runs it directly (MySQL/MariaDB DDL
		 * auto-commits per statement regardless of an open transaction).
		 * @param callable(): void $callback
		 * @return void
		 * @throws \Throwable
		 */
		private function runTransactionally(callable $callback): void {
			if (!$this->platform->supportsTransactionalDDL()) {
				$callback();
				return;
			}

			$connection = $this->entityManager->getConnection();
			$connection->beginTrans();

			try {
				$callback();
			} catch (\Throwable $e) {
				$connection->rollbackTrans();
				throw $e;
			}

			$connection->commitTrans();
		}
	}
