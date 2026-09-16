<?php

	namespace Quellabs\ObjectQuel\Sculpt\Helpers;

	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;

	/**
	 * Generates migration files from schema change descriptors, as real
	 * ObjectQuel DDL statements run through AbstractMigration::query() —
	 * replaces PhinxMigrationBuilder (see Phase 5 of
	 * objectquel-migrations-implementation-plan.md).
	 *
	 * Same input contract as PhinxMigrationBuilder: the $allChanges array
	 * passed to generateMigrationFile() is keyed by table name, each value
	 * a change descriptor — see that class's docblock for the exact shape,
	 * which doesn't change here.
	 *
	 * Per table: a new table becomes one `create tableName (...)`
	 * statement; column/primary-key changes on an existing table become
	 * one combined `alter tableName (add ..., drop ..., retype ...,
	 * primary key (...))` statement (simpler than Phinx's separate fluent
	 * call per operation type — Quel's `alter` already takes multiple
	 * comma-separated ops in one statement); index changes are standalone
	 * `index .../destroy ... on ...` statements, never nested inside
	 * `alter`. Foreign keys are a deliberate second pass over every table,
	 * after every table/column/index change, so a table a new FK
	 * references is guaranteed to already exist — same ordering rule
	 * PhinxMigrationBuilder already used, carried over unchanged.
	 *
	 * Enum and JSON columns need no platform-specific resolution here the
	 * way PhinxMigrationBuilder needed: `enum(...)` is always emitted
	 * verbatim (QuelToSQLCreate/QuelToSQLAlter already pick native ENUM vs.
	 * VARCHAR at DDL-compile time — see Phase 0.1), and 'json' is always
	 * the canonical type name emitted (DDLTypeMapper already renders
	 * 'jsonb' for PostgreSQL — see Phase 0's "Current state" note). The one
	 * remaining platform-aware step is the reverse of that for JSON: a
	 * modified or deleted column's raw introspected type can legitimately
	 * be the platform's native JSON type name (e.g. 'jsonb' on PostgreSQL,
	 * since SchemaComparator::getModifiedColumns()/getDeletedColumns()
	 * return the original un-normalized definitions — see Phase 0.1's
	 * companion fix), so that needs recognizing back to 'json' before
	 * rendering, mirroring SchemaComparator::normalizeColumnDefinition()'s
	 * own JSON step.
	 *
	 * Not reproduced here: PhinxMigrationBuilder's PostgreSQL-fulltext raw-
	 * execute() workaround (Postgres fulltext is native Quel DDL now — see
	 * Phase 0's "Current state" note) and its MySQL auto-increment-needs-
	 * an-index workaround for an identity column that isn't part of the
	 * primary key (an edge case the plan doesn't call out for this phase,
	 * and one the entity/DDL layer elsewhere doesn't treat as a first-class
	 * scenario either — identity columns are the primary key in every
	 * schema this generator has to handle in practice).
	 *
	 * @phpstan-import-type ColumnDefinition from DatabaseAdapter
	 * @phpstan-import-type ForeignKeyDefinition from DatabaseAdapter
	 * @phpstan-import-type ColumnModification from SculptTypes
	 * @phpstan-import-type IndexDefinition from SculptTypes
	 * @phpstan-import-type IndexChangeSet from SculptTypes
	 * @phpstan-import-type ForeignKeyChangeSet from SculptTypes
	 *
	 * @phpstan-type IndexConfig IndexDefinition
	 * @phpstan-type IndexChanges IndexChangeSet
	 * @phpstan-type ForeignKeyConfig ForeignKeyDefinition
	 * @phpstan-type ForeignKeyChanges ForeignKeyChangeSet
	 *
	 * @phpstan-type TableChanges array{
	 *     table_not_exists?: bool,
	 *     added?: array<string, ColumnDefinition>,
	 *     modified?: array<string, ColumnModification>,
	 *     deleted?: array<string, ColumnDefinition>,
	 *     indexes?: IndexChanges,
	 *     foreignKeys?: ForeignKeyChanges
	 * }
	 *
	 * @phpstan-type AllChanges array<string, TableChanges>
	 */
	class QuelMigrationBuilder {

		/** @var DatabaseAdapter Database connection used for live schema queries (existing primary keys, row counts) */
		private DatabaseAdapter $connection;

		/** @var string Absolute path to the directory where migration files are written */
		private string $migrationsPath;

		/** @var PlatformCapabilitiesInterface Describes what the connected database engine supports */
		private PlatformCapabilitiesInterface $platform;

		/** @var SqlIdentifierQuoter */
		private SqlIdentifierQuoter $identifierQuoter;

		/**
		 * @param DatabaseAdapter $adapter Active database connection
		 * @param string $migrationsPath Directory that will receive the generated file
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 */
		public function __construct(DatabaseAdapter $adapter, string $migrationsPath, PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()) {
			$this->connection = $adapter;
			$this->migrationsPath = $migrationsPath;
			$this->platform = $platform;
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
		}

		// -------------------------------------------------------------------------
		// Public API
		// -------------------------------------------------------------------------

		/**
		 * Generate a migration file from a set of schema changes.
		 *
		 * The file is written to $migrationsPath with the format:
		 *   20250603145623_QuelSchemaMigration20250603145623.php
		 *
		 * @param AllChanges $allChanges Table-keyed change descriptors (see class docblock)
		 * @return array{success: bool, message: string, path?: string}
		 * @throws \RuntimeException If a non-nullable added column has no safe backfill value (see buildAddColumnOp())
		 */
		public function generateMigrationFile(array $allChanges): array {
			if (empty($allChanges)) {
				return ['success' => false, 'message' => 'No changes detected. Migration file not created.'];
			}

			$date = date('YmdHis');
			$className = "QuelSchemaMigration{$date}";
			$filename = $this->migrationsPath . '/' . $date . '_' . $className . '.php';

			// Create the migrations directory if it doesn't exist yet.
			// The double is_dir() check guards against a race condition where another
			// process creates the directory between our check and our mkdir() call.
			if (!is_dir($this->migrationsPath) && !mkdir($this->migrationsPath, 0755, true) && !is_dir($this->migrationsPath)) {
				return ['success' => false, 'message' => 'Failed to create migrations directory.'];
			}

			if (file_put_contents($filename, $this->buildMigrationContent($className, $allChanges)) === false) {
				return ['success' => false, 'message' => 'Failed to create migration file.'];
			}

			return ['success' => true, 'message' => 'Migration file created', 'path' => $filename];
		}

		// -------------------------------------------------------------------------
		// Migration file assembly
		// -------------------------------------------------------------------------

		/**
		 * Build the full PHP source code for the migration file.
		 * @param string $className Class name embedded in the generated file
		 * @param AllChanges $allChanges Table-keyed change descriptors
		 * @return string Complete PHP source ready to write to disk
		 */
		private function buildMigrationContent(string $className, array $allChanges): string {
			$normalized = [];

			foreach ($allChanges as $tableName => $changes) {
				$normalized[$tableName] = $this->normalizeChanges($changes);
			}

			$up = [];
			$downColumnsAndIndexes = [];
			$downForeignKeys = [];
			$downDropTables = [];

			// Pass 1: tables, columns, and indexes. Foreign keys are deliberately
			// left for pass 2 below — see class docblock.
			foreach ($normalized as $tableName => $changes) {
				if ($changes['table_not_exists']) {
					$up[] = $this->queryStatement($this->buildCreateTableStatement($tableName, $changes['added']));
					// Table drop is deferred to the very end of down() — see below — so
					// it runs after any foreign key pointing at (or added to) it has
					// already been undone.
					$downDropTables[] = $this->queryStatement("destroy {$tableName}");

					foreach ($changes['indexes']['added'] as $indexName => $indexConfig) {
						$up[] = $this->queryStatement($this->buildAddIndexStatement($tableName, $indexName, $indexConfig));
						$downColumnsAndIndexes[] = $this->queryStatement($this->buildDropIndexStatement($tableName, $indexName));
					}

					continue;
				}

				$alterUp = $this->buildAlterColumnsOps($tableName, $changes, 'up');
				$alterDown = $this->buildAlterColumnsOps($tableName, $changes, 'down');

				if ($alterUp !== []) {
					$up[] = $this->queryStatement("alter {$tableName} (" . implode(', ', $alterUp) . ")");
				}

				if ($alterDown !== []) {
					$downColumnsAndIndexes[] = $this->queryStatement("alter {$tableName} (" . implode(', ', $alterDown) . ")");
				}

				foreach ($changes['indexes']['added'] as $indexName => $indexConfig) {
					$up[] = $this->queryStatement($this->buildAddIndexStatement($tableName, $indexName, $indexConfig));
					$downColumnsAndIndexes[] = $this->queryStatement($this->buildDropIndexStatement($tableName, $indexName));
				}

				foreach ($changes['indexes']['modified'] as $indexName => $configs) {
					$up[] = $this->queryStatement($this->buildDropIndexStatement($tableName, $indexName));
					$up[] = $this->queryStatement($this->buildAddIndexStatement($tableName, $indexName, $configs['entity']));
					$downColumnsAndIndexes[] = $this->queryStatement($this->buildDropIndexStatement($tableName, $indexName));
					$downColumnsAndIndexes[] = $this->queryStatement($this->buildAddIndexStatement($tableName, $indexName, $configs['database']));
				}

				foreach ($changes['indexes']['deleted'] as $indexName => $indexConfig) {
					$up[] = $this->queryStatement($this->buildDropIndexStatement($tableName, $indexName));
					$downColumnsAndIndexes[] = $this->queryStatement($this->buildAddIndexStatement($tableName, $indexName, $indexConfig));
				}
			}

			// Pass 2: foreign keys, once every table (new or existing) is guaranteed to exist.
			foreach ($normalized as $tableName => $changes) {
				$foreignKeys = $changes['foreignKeys'];

				if (!empty($foreignKeys['added'])) {
					$up[] = $this->queryStatement($this->buildAlterForeignKeysStatement($tableName, $this->buildAddForeignKeyOps($foreignKeys['added'])));
					$downForeignKeys[] = $this->queryStatement($this->buildAlterForeignKeysStatement($tableName, $this->buildDropForeignKeyOps($foreignKeys['added'])));
				}

				if (!empty($foreignKeys['modified'])) {
					$entitySide = array_map(static fn(array $configs) => $configs['entity'], $foreignKeys['modified']);
					$databaseSide = array_map(static fn(array $configs) => $configs['database'], $foreignKeys['modified']);

					$up[] = $this->queryStatement($this->buildAlterForeignKeysStatement(
						$tableName,
						[...$this->buildDropForeignKeyOps($databaseSide), ...$this->buildAddForeignKeyOps($entitySide)]
					));
					$downForeignKeys[] = $this->queryStatement($this->buildAlterForeignKeysStatement(
						$tableName,
						[...$this->buildDropForeignKeyOps($entitySide), ...$this->buildAddForeignKeyOps($databaseSide)]
					));
				}

				if (!empty($foreignKeys['deleted'])) {
					$up[] = $this->queryStatement($this->buildAlterForeignKeysStatement($tableName, $this->buildDropForeignKeyOps($foreignKeys['deleted'])));
					$downForeignKeys[] = $this->queryStatement($this->buildAlterForeignKeysStatement($tableName, $this->buildAddForeignKeyOps($foreignKeys['deleted'])));
				}
			}

			// down() must undo in the exact reverse of up(): foreign keys first — a
			// column or table this migration constrains can't be altered or dropped
			// while that constraint still exists — then column/index changes, then
			// finally the tables this migration created.
			$down = [...$downForeignKeys, ...$downColumnsAndIndexes, ...$downDropTables];

			$upBody = implode("\n", $up);
			$downBody = implode("\n", $down);

			return <<<PHP
<?php

use Quellabs\ObjectQuel\Migration\AbstractMigration;

class $className extends AbstractMigration {

    /**
     * This migration was automatically generated by ObjectQuel
     */

    public function up(): void {
$upBody
    }

    public function down(): void {
$downBody
    }
}
PHP;
		}

		/**
		 * Ensure all expected keys exist in a change descriptor. See
		 * PhinxMigrationBuilder::normalizeChanges() — identical logic,
		 * unrelated to Phinx.
		 * @param TableChanges $changes Raw change descriptor, possibly missing optional keys
		 * @return array{
		 *     added: array<string, ColumnDefinition>,
		 *     modified: array<string, ColumnModification>,
		 *     deleted: array<string, ColumnDefinition>,
		 *     indexes: IndexChanges,
		 *     foreignKeys: ForeignKeyChanges,
		 *     table_not_exists: bool
		 * }
		 */
		private function normalizeChanges(array $changes): array {
			$defaults = [
				'added'            => [],
				'modified'         => [],
				'deleted'          => [],
				'indexes'          => ['added' => [], 'modified' => [], 'deleted' => []],
				'foreignKeys'      => ['added' => [], 'modified' => [], 'deleted' => []],
				'table_not_exists' => false,
			];

			$merged = array_merge($defaults, $changes);
			$merged['indexes'] = array_merge($defaults['indexes'], $changes['indexes'] ?? []);
			$merged['foreignKeys'] = array_merge($defaults['foreignKeys'], $changes['foreignKeys'] ?? []);
			return $merged;
		}

		/**
		 * Wraps a Quel statement as a PHP source line calling
		 * AbstractMigration::query(). addslashes() is the second of the two
		 * escaping layers the plan calls for — the first (QuelLiteralEscaper)
		 * already ran on any literal spliced into $quel; this layer escapes
		 * the *whole* resulting Quel text for its own PHP single-quoted
		 * string literal, the same job MigrationCodeBuilder::execute()'s
		 * addslashes($segment['sql']) call already does for raw SQL.
		 * @param string $quel
		 * @return string
		 */
		private function queryStatement(string $quel): string {
			return "        \$this->query('" . addslashes($quel) . "');";
		}

		// -------------------------------------------------------------------------
		// Table-level statement builders
		// -------------------------------------------------------------------------

		/**
		 * Build a `create tableName (...)` statement for a brand-new table.
		 * @param string $tableName
		 * @param array<string, ColumnDefinition> $columns
		 * @return string
		 */
		private function buildCreateTableStatement(string $tableName, array $columns): string {
			$primaryKeys = $this->analyzeColumns($columns)['primaryKeys'];

			$columnDefs = [];

			foreach ($columns as $columnName => $definition) {
				$columnDefs[] = $this->renderColumnDefinition($columnName, $definition);
			}

			if ($primaryKeys !== []) {
				$columnDefs[] = 'primary key (' . implode(', ', $primaryKeys) . ')';
			}

			return "create {$tableName} (" . implode(', ', $columnDefs) . ')';
		}

		/**
		 * Build the comma-separated list of `alter`'s column/primary-key
		 * sub-operations for one table, in one direction. `add` ops always
		 * precede `primary key (...)` so a newly-added primary-key column
		 * exists by the time the primary key change runs (these compile to
		 * separate, sequentially-run SQL statements — see QuelToSQLAlter).
		 * @param string $tableName
		 * @param array{added: array<string, ColumnDefinition>, modified: array<string, ColumnModification>, deleted: array<string, ColumnDefinition>} $changes
		 * @param 'up'|'down' $direction
		 * @return list<string>
		 * @throws \RuntimeException If a non-nullable added column has no safe backfill value (up() only — see buildAddColumnOp())
		 */
		private function buildAlterColumnsOps(string $tableName, array $changes, string $direction): array {
			$ops = [];

			if ($direction === 'up') {
				foreach ($changes['added'] as $columnName => $definition) {
					$ops[] = $this->buildAddColumnOp($tableName, $columnName, $definition);
				}

				foreach ($changes['modified'] as $columnName => $modification) {
					$ops[] = 'retype ' . $this->renderColumnDefinition($columnName, $modification['to']);
				}

				foreach (array_keys($changes['deleted']) as $columnName) {
					$ops[] = "drop {$columnName}";
				}

				$newPrimaryKeys = $this->analyzeColumns($changes['added'])['primaryKeys'];

				if ($newPrimaryKeys !== []) {
					$existing = $this->connection->getPrimaryKeyColumns($tableName);
					$merged = array_values(array_unique([...$existing, ...$newPrimaryKeys]));

					if ($merged !== $existing) {
						$ops[] = 'primary key (' . implode(', ', $merged) . ')';
					}
				}
			} else {
				// down(): mirror image of up() — added columns get dropped,
				// deleted columns get added back, modifications revert to
				// their pre-migration definition. No primary-key restoration
				// here, mirroring PhinxMigrationBuilder's own down() (which
				// never restores a pre-migration primary key either) — see
				// class docblock.
				foreach (array_keys($changes['added']) as $columnName) {
					$ops[] = "drop {$columnName}";
				}

				foreach ($changes['modified'] as $columnName => $modification) {
					$ops[] = 'retype ' . $this->renderColumnDefinition($columnName, $modification['from']);
				}

				foreach ($changes['deleted'] as $columnName => $definition) {
					$ops[] = 'add ' . $this->renderColumnDefinition($columnName, $definition);
				}
			}

			return $ops;
		}

		/**
		 * Build one `add` op for a newly-added column, appending a
		 * `backfill` clause when the column is non-nullable and the table
		 * already has rows — see Phase 0.2/Phase 5's backfill design.
		 * Never needed for a brand-new table (buildCreateTableStatement()
		 * never calls this) — a table with no rows yet needs no backfill.
		 * @param string $tableName
		 * @param string $columnName
		 * @param ColumnDefinition $definition
		 * @return string
		 * @throws \RuntimeException If the column is non-nullable, the table has rows, and the entity declares no default
		 */
		private function buildAddColumnOp(string $tableName, string $columnName, array $definition): string {
			$op = 'add ' . $this->renderColumnDefinition($columnName, $definition);

			if (!empty($definition['nullable'])) {
				return $op;
			}

			if (!$this->tableHasRows($tableName)) {
				return $op;
			}

			if ($definition['default'] === null) {
				throw new \RuntimeException(
					"Cannot add non-nullable column '{$tableName}.{$columnName}': the table has existing rows and " .
					"the entity declares no default to backfill them with. Add @Orm\\Column(default=...) to the " .
					"entity, or write this migration by hand with make:migration."
				);
			}

			$default = $definition['default'];

			if (!is_scalar($default) && !$default instanceof \Stringable) {
				throw new \RuntimeException(
					"Cannot add non-nullable column '{$tableName}.{$columnName}': its declared default is not a " .
					"value 'backfill' can express as a string literal. Write this migration by hand with make:migration."
				);
			}

			$escaped = QuelLiteralEscaper::escape((string)$default);
			return "{$op} backfill '{$escaped}'";
		}

		/**
		 * Whether $tableName currently has at least one row — checked live
		 * against the connected database, the same way the rest of the
		 * diffing this generator consumes is live (see
		 * EntitySchemaAnalyzer).
		 * @param string $tableName
		 * @return bool
		 */
		private function tableHasRows(string $tableName): bool {
			$quotedTable = $this->identifierQuoter->quoteIdentifier($tableName);
			$statement = $this->connection->execute("SELECT COUNT(*) AS cnt FROM {$quotedTable}");

			if ($statement === null) {
				return false;
			}

			$row = $statement->fetchAssoc();
			return isset($row['cnt']) && (int)$row['cnt'] > 0;
		}

		// -------------------------------------------------------------------------
		// Index statement builders
		// -------------------------------------------------------------------------

		/**
		 * Build a standalone `index [unique|fulltext] on table is name
		 * (cols)` statement.
		 * @param string $tableName
		 * @param string $indexName
		 * @param IndexConfig $indexConfig
		 * @return string
		 */
		private function buildAddIndexStatement(string $tableName, string $indexName, array $indexConfig): string {
			$type = strtoupper($indexConfig['type']);

			$modifier = match ($type) {
				'FULLTEXT' => 'fulltext ',
				'UNIQUE' => 'unique ',
				default => '',
			};

			$columns = implode(', ', $indexConfig['columns']);
			return "index {$modifier}on {$tableName} is {$indexName} ({$columns})";
		}

		/**
		 * Build a standalone `destroy name on table` statement.
		 * @param string $tableName
		 * @param string $indexName
		 * @return string
		 */
		private function buildDropIndexStatement(string $tableName, string $indexName): string {
			return "destroy {$indexName} on {$tableName}";
		}

		// -------------------------------------------------------------------------
		// Foreign-key statement builders
		// -------------------------------------------------------------------------

		/**
		 * @param string $tableName
		 * @param list<string> $ops
		 * @return string
		 */
		private function buildAlterForeignKeysStatement(string $tableName, array $ops): string {
			return "alter {$tableName} (" . implode(', ', $ops) . ')';
		}

		/**
		 * @param array<string, ForeignKeyConfig> $foreignKeys
		 * @return list<string>
		 */
		private function buildAddForeignKeyOps(array $foreignKeys): array {
			$ops = [];

			foreach ($foreignKeys as $config) {
				$column = $config['columns'][0];
				$referencedColumn = $config['referencedColumns'][0];
				$onDelete = strtolower($config['onDelete']);
				$onUpdate = strtolower($config['onUpdate']);

				$ops[] = "add foreign key ({$column}) references {$config['referencedTable']} ({$referencedColumn}) on delete {$onDelete} on update {$onUpdate}";
			}

			return $ops;
		}

		/**
		 * @param array<string, ForeignKeyConfig> $foreignKeys
		 * @return list<string>
		 */
		private function buildDropForeignKeyOps(array $foreignKeys): array {
			$ops = [];

			foreach ($foreignKeys as $config) {
				$ops[] = "drop foreign key ({$config['columns'][0]})";
			}

			return $ops;
		}

		// -------------------------------------------------------------------------
		// Column helpers
		// -------------------------------------------------------------------------

		/**
		 * Render a single `name = [unsigned] type[(args)] [nullable]
		 * [identity]` column definition, matching ColumnDefinitionClause's
		 * grammar exactly (see ObjectQuel/Rules/ColumnDefinitionClause.php).
		 * @param string $columnName
		 * @param ColumnDefinition $definition
		 * @return string
		 */
		private function renderColumnDefinition(string $columnName, array $definition): string {
			$type = $this->resolveType($definition);

			if ($type === 'enum') {
				$typeExpr = 'enum(' . $this->renderEnumValues($definition['values'] ?? []) . ')';
			} else {
				$unsigned = !empty($definition['unsigned']) ? 'unsigned ' : '';
				$typeExpr = $unsigned . $type . $this->renderTypeArguments($type, $definition);
			}

			$constraints = [];

			if (!empty($definition['nullable'])) {
				$constraints[] = 'nullable';
			}

			if (!empty($definition['identity'])) {
				$constraints[] = 'identity';
			}

			$constraintsExpr = $constraints === [] ? '' : ' ' . implode(' ', $constraints);

			return "{$columnName} = {$typeExpr}{$constraintsExpr}";
		}

		/**
		 * @param string[] $values
		 * @return string
		 */
		private function renderEnumValues(array $values): string {
			return implode(', ', array_map(
				fn(string $value) => "'" . QuelLiteralEscaper::escape($value) . "'",
				$values
			));
		}

		/**
		 * Column types DDLTypeMapper never renders a limit for, on any
		 * supported engine — each has its own fixed/native SQL type
		 * (INT, UUID, TEXT, etc.) that ignores the limit argument
		 * entirely (see DDLTypeMapper's per-dialect match arms). Emitting
		 * `(n)` for these is dead syntax that round-trips through the
		 * migration but affects no generated DDL, e.g. a uuid column's
		 * fixed 36-char length or an integer column's legacy MySQL
		 * display width. 'char' and the VARCHAR/VARBINARY fallback used
		 * for 'string'/unrecognized types are deliberately not listed
		 * here — those do consume the limit on at least one platform.
		 */
		private const array TYPES_WITHOUT_DDL_LIMIT = [
			'tinyinteger', 'smallinteger', 'integer', 'biginteger',
			'float', 'decimal',
			'boolean',
			'date', 'datetime', 'time', 'timestamp',
			'text', 'blob',
			'json',
			'uuid', 'year',
		];

		/**
		 * The `(precision,scale)` or `(limit)` type-arguments suffix —
		 * mutually exclusive in Quel's grammar, unlike Phinx's option
		 * array. Precision takes priority: a decimal-like column has
		 * precision set and no meaningful limit. The limit itself is
		 * only emitted for types DDLTypeMapper actually consults it for
		 * — see TYPES_WITHOUT_DDL_LIMIT.
		 * @param string $type Resolved column type (see resolveType())
		 * @param ColumnDefinition $definition
		 * @return string
		 */
		private function renderTypeArguments(string $type, array $definition): string {
			if (!empty($definition['precision'])) {
				$scale = $definition['scale'] ?? 0;
				return "({$definition['precision']},{$scale})";
			}

			if (
				!empty($definition['limit']) &&
				is_int($definition['limit']) &&
				!in_array($type, self::TYPES_WITHOUT_DDL_LIMIT, true)
			) {
				return "({$definition['limit']})";
			}

			return '';
		}

		/**
		 * Recognizes the platform's native JSON type name back to the
		 * canonical 'json' — needed because a modified/deleted column's
		 * definition can carry the raw introspected type (e.g. 'jsonb' on
		 * PostgreSQL) rather than the canonical name; see class docblock.
		 * No enum resolution needed here — enum is always emitted verbatim
		 * regardless of platform (see class docblock).
		 * @param ColumnDefinition $definition
		 * @return string
		 */
		private function resolveType(array $definition): string {
			$type = $definition['type'];

			if ($type === $this->platform->getNativeJsonType() && $type !== 'json') {
				return 'json';
			}

			return $type;
		}

		/**
		 * Scan a set of column definitions and extract primary key column
		 * names, in declaration order.
		 * @param array<string, ColumnDefinition> $columns
		 * @return array{primaryKeys: list<string>}
		 */
		private function analyzeColumns(array $columns): array {
			$primaryKeys = [];

			foreach ($columns as $columnName => $definition) {
				if (!empty($definition['primary_key'])) {
					$primaryKeys[] = $columnName;
				}
			}

			return ['primaryKeys' => $primaryKeys];
		}
	}
