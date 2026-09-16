<?php

	namespace Quellabs\ObjectQuel\Migration;

	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Base class for hand-written and generated migrations. A migration is a
	 * plain PHP class with `up()`/`down()`, each a sequence of ObjectQuel DDL
	 * (and, for hand-written migrations, DML) statements run through
	 * `query()`.
	 *
	 * Deliberately has no `execute(string $rawSql)` escape hatch — if Quel
	 * DDL can't express something yet, that's a gap to close in the DDL
	 * layer (`create`/`alter`/`destroy`/`index`), not a reason to fall back
	 * to raw SQL from inside a migration (see
	 * objectquel-migrations-implementation-plan.md, Phase 1).
	 *
	 * File/class naming: `<YmdHis>_<ClassName>.php`, version = the
	 * timestamp — the same scheme Phinx-style migration tools already use,
	 * kept for sortable, diffable filenames rather than inventing a new
	 * ordering scheme.
	 */
	abstract class AbstractMigration {

		public function __construct(private readonly EntityManager $entityManager) {
		}

		/**
		 * Applies this migration.
		 * @return void
		 */
		abstract public function up(): void;

		/**
		 * Reverts this migration.
		 * @return void
		 */
		abstract public function down(): void;

		/**
		 * Runs a single ObjectQuel statement.
		 * @param string $quel
		 * @return void
		 */
		protected function query(string $quel): void {
			$this->entityManager->executeQuery($quel);
		}
	}
