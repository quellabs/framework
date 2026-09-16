<?php
	
	namespace Quellabs\ObjectQuel\DatabaseAdapter;
	
	use Cake\Database\Schema\CollectionInterface;
	use Cake\Database\Schema\Collection as SchemaCollection;
	use Cake\Database\StatementInterface;
	use Cake\Database\Connection;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\MysqlSchemaIntrospector;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\NullSchemaIntrospector;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\PostgresSchemaIntrospector;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\SchemaIntrospectorInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\SqlServerSchemaIntrospector;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\SqliteSchemaIntrospector;

	/**
	 * Database adapter that ties ObjectQuel and CakePHP Database together
	 * Wraps CakePHP's database connection to provide ObjectQuel-specific functionality
	 * including schema introspection, transaction management, and cross-database compatibility.
	 *
	 * @phpstan-type ColumnDefinition array{
	 *     type: string,
	 *     php_type: string,
	 *     limit: int|array<int, int>|null,
	 *     default: mixed,
	 *     nullable: bool,
	 *     precision: int|null,
	 *     scale: int|null,
	 *     unsigned: bool,
	 *     generated: mixed,
	 *     identity: bool,
	 *     primary_key: bool,
	 *     values: array<int, string>|null
	 * }
	 *
	 * @phpstan-type IndexDefinition array{
	 *     type: 'primary'|'unique'|'index'|'fulltext',
	 *     columns: string[],
	 *     length: array<int, int>|null,
	 *     name?: string
	 * }
	 *
	 * @phpstan-type ForeignKeyDefinition array{
	 *     columns: string[],
	 *     referencedTable: string,
	 *     referencedColumns: string[],
	 *     onDelete: string,
	 *     onUpdate: string
	 * }
	 *
	 * @phpstan-type IndexUsageStats array{reads: int, writes: int}
	 */
	class DatabaseAdapter {
		
		/** @var array|string[] The index types ObjectQuel supports */
		const array INDEX_TYPES = ['primary', 'unique', 'index', 'fulltext'];

		/** @var Connection CakePHP database connection instance */
		protected Connection $connection;
		
		/** @var int Error code from the last failed database operation (0 = no error) */
		protected int $last_error;
		
		/** @var string Error message from the last failed database operation */
		protected string $last_error_message;
		
		/** @var int Current nesting level of active transactions (0 = no active transaction) */
		protected int $transaction_depth;

		/** @var bool Set when a nested rollbackTrans() marks the transaction rollback-only — see beginTrans()/commitTrans() */
		protected bool $transaction_rollback_only;

		/** @var string|null Cached database type identifier (null = not yet determined) */
		private ?string $databaseTypeCache;

		/**
		 * Cached per-engine schema introspector (columns/foreign keys/index
		 * usage stats — see SchemaIntrospectorInterface), lazily created by
		 * getSchemaIntrospector() and cached for the lifetime of this adapter,
		 * since getDatabaseType() cannot change after construction. Defaulted
		 * inline (unlike the other cache fields below) rather than only in
		 * the constructor: getSchemaIntrospector() is private, so a partial
		 * mock built with disableOriginalConstructor() (see
		 * DatabaseAdapterForeignKeyPostgresTest and friends) can't stub it
		 * the way it stubs getDatabaseType(), and would otherwise hit this
		 * property uninitialized.
		 * @var SchemaIntrospectorInterface|null
		 */
		private ?SchemaIntrospectorInterface $schemaIntrospectorCache = null;

		/**
		 * Cached SQL Server database compatibility level (e.g. 170 for SQL
		 * Server 2025), fetched via DATABASEPROPERTYEX(). Null means "not yet
		 * queried, or the query failed". Only meaningful when getDatabaseType()
		 * is 'sqlsrv' — irrelevant for every other engine.
		 * @var int|null
		 */
		private ?int $sqlServerCompatibilityLevelCache;
		
		/**
		 * Constructs a new database adapter instance
		 * @param Connection $connection CakePHP database connection to wrap
		 */
		public function __construct(Connection $connection) {
			$this->connection = $connection;
			$this->last_error = 0;
			$this->last_error_message = '';
			$this->transaction_depth = 0;
			$this->transaction_rollback_only = false;
			$this->databaseTypeCache = null;
			$this->sqlServerCompatibilityLevelCache = null;

			// SQLite disables foreign-key enforcement per-connection by default, even when
			// the schema declares real FK constraints. Without this, a constraint generated
			// from an @Orm\ForeignKey annotation (or any FK already present in the schema)
			// would silently do nothing on SQLite.
			if ($this->getDatabaseType() === 'sqlite') {
				$this->execute('PRAGMA foreign_keys = ON');
			}
		}
		
		// ==================== Connection & Driver Info ====================
		
		/**
		 * Returns the wrapped CakePHP database connection
		 * @return Connection The underlying CakePHP connection instance
		 */
		public function getConnection(): Connection {
			return $this->connection;
		}
		
		/**
		 * Determines the database type from the CakePHP driver class
		 * @return string Database type identifier: 'mysql', 'mariadb', 'pgsql', 'sqlite', or 'sqlsrv'
		 */
		public function getDatabaseType(): string {
			if ($this->databaseTypeCache !== null) {
				return $this->databaseTypeCache;
			}
			
			$driver = $this->connection->getDriver();
			
			$this->databaseTypeCache = match (get_class($driver)) {
				'Cake\Database\Driver\Postgres' => 'pgsql',
				'Cake\Database\Driver\Sqlite' => 'sqlite',
				'Cake\Database\Driver\Sqlserver' => 'sqlsrv',
				default => stripos($driver->version(), 'mariadb') !== false ? 'mariadb' : 'mysql'
			};
			
			return $this->databaseTypeCache;
		}
		
		/**
		 * Returns the normalized server version string.
		 *
		 * MariaDB advertises itself to MySQL clients with a compatibility prefix to
		 * maintain protocol compatibility with older MySQL clients:
		 *   "5.5.5-10.6.1-MariaDB"
		 *
		 * CakePHP's Driver::version() returns this raw string verbatim. This method
		 * strips the compatibility prefix so callers always receive the real version
		 * number regardless of engine, making version_compare() calls safe for both
		 * MySQL and MariaDB.
		 *
		 * @return string Normalized version string (e.g. "8.0.32", "10.6.1-MariaDB")
		 */
		public function getServerVersion(): string {
			// Fetch version number
			$version = $this->connection->getDriver()->version();
			
			// MariaDB prefixes its version string with "5.5.5-" for MySQL client
			// compatibility. Strip it to expose the real version number.
			if (preg_match('/^\d+\.\d+\.\d+-(\d+\.\d+\.\d+-MariaDB.*)$/', $version, $matches)) {
				return $matches[1];
			} else {
				return $version;
			}
		}
		
		/**
		 * Returns the schema collection for database introspection.
		 *
		 * Deliberately builds a plain, uncached SchemaCollection directly
		 * rather than calling Connection::getSchemaCollection() — that method
		 * wraps it in a CachedCollection whenever the connection's
		 * `cacheMetadata` config is truthy, which requires a configured Cache
		 * pool (the cakephp/cache package). ObjectQuel doesn't depend on
		 * cakephp/cache, and schema introspection here isn't hot-path enough
		 * to need cross-request caching, so this sidesteps a hard dependency
		 * the connection's own config might otherwise silently require.
		 * @return CollectionInterface Schema collection providing access to table metadata
		 */
		public function getSchemaCollection(): CollectionInterface {
			return new SchemaCollection($this->connection);
		}

		/**
		 * Returns the per-engine schema introspector for the connected
		 * database (columns/foreign keys/index usage stats — see
		 * SchemaIntrospectorInterface), matching the same closed enumeration
		 * getDatabaseType() and every other engine-dispatch match() in this
		 * class already use. 'default' falls back to NullSchemaIntrospector
		 * for a future unmapped engine value, not a branch reachable today.
		 * @return SchemaIntrospectorInterface
		 */
		private function getSchemaIntrospector(): SchemaIntrospectorInterface {
			if ($this->schemaIntrospectorCache !== null) {
				return $this->schemaIntrospectorCache;
			}

			return $this->schemaIntrospectorCache = match ($this->getDatabaseType()) {
				'sqlite'           => new SqliteSchemaIntrospector($this),
				'mysql', 'mariadb' => new MysqlSchemaIntrospector($this),
				'pgsql'            => new PostgresSchemaIntrospector($this),
				'sqlsrv'           => new SqlServerSchemaIntrospector($this),
				default            => new NullSchemaIntrospector(),
			};
		}

		// ==================== Schema Introspection ====================

		/**
		 * Retrieves a list of all tables in the database (excluding views)
		 * @return string[] List of table names
		 */
		public function getTables(): array {
			$schemaCollection = $this->getSchemaCollection();
			return $schemaCollection->listTablesWithoutViews();
		}

		/**
		 * Retrieves detailed column definitions for a database table, via
		 * native per-engine SQL introspection (see
		 * objectquel-phinx-removal-plan.md — this replaced Phinx's
		 * AdapterInterface::getColumns()).
		 * @param string $tableName Name of the table to analyze
		 * @return array<string, ColumnDefinition>
		 */
		public function getColumns(string $tableName): array {
			return $this->getSchemaIntrospector()->getColumns($tableName);
		}
		
		/**
		 * Returns the current database's compatibility level on SQL Server
		 * (e.g. 170 for SQL Server 2025), or null if it could not be determined
		 * or the connection is not SQL Server. Result is cached for the lifetime
		 * of this adapter instance.
		 *
		 * Compatibility level is a per-database setting independent of the
		 * engine version — a SQL Server 2025 instance can host a database still
		 * pinned to an older compatibility level (e.g. migrated without ever
		 * raising it), so the engine version returned by getServerVersion()
		 * alone cannot answer "which T-SQL features does this database support".
		 *
		 * @return int|null
		 */
		public function getSqlServerCompatibilityLevel(): ?int {
			// Return cache
			if ($this->sqlServerCompatibilityLevelCache !== null) {
				return $this->sqlServerCompatibilityLevelCache;
			}
			
			// DB_NAME() resolves to the current connection's database, so this
			// works without the caller needing to know or pass the database name.
			$stmt = $this->execute(
				"SELECT DATABASEPROPERTYEX(DB_NAME(), 'CompatibilityLevel') AS compat_level"
			);
			
			if ($stmt === null) {
				return null;
			}
			
			$row = $stmt->fetchAssoc();
			$stmt->closeCursor();
			
			if (!$row || !isset($row['compat_level'])) {
				return null;
			}
			
			return $this->sqlServerCompatibilityLevelCache = (int)$row['compat_level'];
		}
		
		/**
		 * Retrieves the primary key column name for a table
		 * For composite primary keys, returns only the first column.
		 * @param string $tableName Name of the table
		 * @return string Primary key column name, or empty string if no primary key exists
		 */
		public function getPrimaryKey(string $tableName): string {
			// Get all primary key columns
			$primaryKeyColumns = $this->getPrimaryKeyColumns($tableName);
			
			// Return first primary key column (assumes single-column PK)
			// Uses null coalescing operator to return empty string if no columns exist
			return $primaryKeyColumns[0] ?? '';
		}
		
		/**
		 * Retrieves all columns that make up the primary key for a table
		 * Supports both single-column and composite primary keys.
		 * @param string $tableName Name of the table
		 * @return string[] List of column names in the primary key, or empty array if none exists
		 */
		public function getPrimaryKeyColumns(string $tableName): array {
			// Get the schema descriptor for the specified table
			$schema = $this->getSchemaCollection()->describe($tableName);
			
			// Iterate through all constraints defined on the table
			foreach ($schema->constraints() as $constraint) {
				// Get detailed information about the current constraint
				$constraintData = $schema->getConstraint($constraint);
				
				// Check if this constraint is a primary key constraint
				if (isset($constraintData['type']) && $constraintData['type'] === 'primary') {
					/**
					 * Return the column names that make up the primary key
					 * This supports both single and composite primary keys
					 * @var array{type: string, columns: array<string>} $constraintData
					 */
					return $constraintData['columns'];
				}
			}
			
			// Return an empty array if no primary key could be determined
			// This indicates the table has no primary key or it couldn't be detected
			return [];
		}
		
		/**
		 * Retrieves index definitions for a database table.
		 * @param string $tableName
		 * @return array<string, IndexDefinition>
		 */
		public function getIndexes(string $tableName): array {
			// Fetch table schema
			$tableSchema = $this->getSchemaCollection()->describe($tableName);
			
			// Collect indexes
			$result = [];
			
			foreach ($tableSchema->indexes() as $indexName) {
				// Fetch index
				$index = $tableSchema->getIndex($indexName);
				
				// getIndex() can theoretically return null on race conditions or
				// schema inconsistencies, so guard defensively.
				if ($index === null) {
					continue;
				}
				
				// Store the index details in the result array, using the index name as key
				// Index details include columns, type (PRIMARY, UNIQUE, INDEX), and other properties
				/** @var array{type: string, columns: array<string>, length: array<int,int>|null} $index */
				if (in_array($index['type'], self::INDEX_TYPES, true)) {
					$type = $index['type'];
				} else {
					$type = 'index';
				}
				
				$result[$indexName] = [
					'type'    => $type,
					'columns' => $index['columns'],
					'length'  => $index['length'],
				];
			}
			
			// CakePHP's schema model treats UNIQUE indexes as table constraints, not as
			// indexes: $tableSchema->indexes() only ever yields plain KEY / FULLTEXT
			// entries. Without reading constraints() too, every UNIQUE index would be
			// invisible here and would be reported as "missing" by IndexComparator on
			// every single make:migrations run, forever, even immediately after it was
			// created.
			foreach ($tableSchema->constraints() as $constraintName) {
				$constraint = $tableSchema->getConstraint($constraintName);
				
				// getConstraint() can theoretically return null on race conditions or
				// schema inconsistencies, so guard defensively. Only unique constraints
				// are relevant here; primary keys and foreign keys are handled elsewhere.
				if ($constraint === null || $constraint['type'] !== 'unique') {
					continue;
				}
				
				/** @var array{type: string, columns: array<string>} $constraint */
				$result[$constraintName] = [
					'type'    => 'unique',
					'columns' => $constraint['columns'],
					'length'  => null,
				];
			}
			
			return $result;
		}

		/**
		 * Whether a SQL Server table currently has a fulltext index. T-SQL
		 * fulltext indexes live in sys.fulltext_indexes, not in the ordinary
		 * schema-collection index/constraint lists getIndexes() reads from,
		 * so they're otherwise invisible to it — see
		 * objectquel-destroy-index-plan.md's "Fulltext index destroy on
		 * sqlsrv/sqlite" section.
		 * @param string $tableName
		 * @return bool
		 */
		public function hasSqlServerFulltextIndex(string $tableName): bool {
			$statement = $this->execute("
				SELECT 1 AS found
				FROM sys.fulltext_indexes fi
				JOIN sys.tables t ON t.object_id = fi.object_id
				WHERE t.name = :tableName
			", ['tableName' => $tableName]);

			if ($statement === null) {
				return false;
			}

			$row = $statement->fetchAssoc();
			$statement->closeCursor();

			return (bool)$row;
		}

		/**
		 * Reads a table-level extended property — SQL Server's standard,
		 * inspectable (via sys.extended_properties, same as any DB tool)
		 * object-annotation mechanism, not a hidden framework-side registry.
		 * Used by QuelToSQLCreateIndex/QuelToSQLDestroyIndex to correlate a
		 * QUEL index name against a table's fulltext index, which is itself
		 * unnamed at the T-SQL level (see hasSqlServerFulltextIndex() and
		 * objectquel-destroy-index-plan.md). Assumes the default 'dbo'
		 * schema, matching every other sqlsrv code path in this codebase —
		 * no schema-qualification exists for QUEL-created objects.
		 * @param string $tableName
		 * @param string $propertyName
		 * @return string|null The property's value, or null if unset
		 */
		public function getSqlServerExtendedProperty(string $tableName, string $propertyName): ?string {
			$statement = $this->execute("
				SELECT CAST(value AS NVARCHAR(4000)) AS property_value
				FROM sys.extended_properties
				WHERE major_id = OBJECT_ID(:tableName)
				  AND minor_id = 0
				  AND class = 1
				  AND name = :propertyName
			", ['tableName' => $tableName, 'propertyName' => $propertyName]);

			if ($statement === null) {
				return null;
			}

			$row = $statement->fetchAssoc();
			$statement->closeCursor();

			return $row['property_value'] ?? null;
		}

		/**
		 * Returns the base table name a SQLite FTS5 external-content
		 * virtual table named $indexName was built against, or null if no
		 * such virtual table exists. The FTS5 table is an ordinary
		 * sqlite_master row (type='table') indistinguishable from any other
		 * table except by its own `CREATE VIRTUAL TABLE ... USING
		 * fts5(...)` text — parsed here for the `content=` option
		 * QuelToSQLCreateIndex::compileSqliteFulltext() always sets to the
		 * base table name. See objectquel-destroy-index-plan.md's
		 * "Fulltext index destroy on sqlsrv/sqlite" section.
		 * @param string $indexName
		 * @return string|null
		 */
		public function getSqliteFts5BaseTable(string $indexName): ?string {
			$statement = $this->execute(
				"SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name",
				['name' => $indexName]
			);

			if ($statement === null) {
				return null;
			}

			$row = $statement->fetchAssoc();
			$statement->closeCursor();

			if (!$row || !isset($row['sql']) || !preg_match('/using\s+fts5/i', $row['sql'])) {
				return null;
			}

			if (!preg_match("/content\s*=\s*'([^']*)'/i", $row['sql'], $matches)) {
				return null;
			}

			return $matches[1];
		}

		/**
		 * Retrieves foreign key constraint definitions for a database table.
		 *
		 * Implemented for every engine getDatabaseType() can identify; the
		 * 'default' branch is a defensive fallback for a future unmapped engine,
		 * returning an empty array rather than throwing. Callers that diff
		 * against the result should check
		 * PlatformCapabilitiesInterface::supportsForeignKeyIntrospection() first —
		 * an empty result there means "not introspectable", not "none exist".
		 * @param string $tableName
		 * @return array<string, ForeignKeyDefinition> Constraint name => definition
		 */
		public function getForeignKeys(string $tableName): array {
			return $this->getSchemaIntrospector()->getForeignKeys($tableName);
		}

		// ==================== Index Usage Statistics ====================

		/**
		 * Retrieves per-index read/write usage counters. Only MySQL/MariaDB
		 * (performance_schema) and PostgreSQL (pg_stat_user_indexes) expose this;
		 * other engines return null. Null means "unavailable", not "zero".
		 * @param string[] $tables Table names to fetch statistics for
		 * @return array<string, array<string, IndexUsageStats>>|null Table name => index name => stats
		 */
		public function getIndexUsageStatistics(array $tables): ?array {
			return $this->getSchemaIntrospector()->getIndexUsageStatistics($tables);
		}

		// ==================== Query Execution ====================

		/**
		 * Executes a SQL query with optional parameter binding
		 * @param string $query SQL query to execute
		 * @param array<int|string, mixed> $parameters Parameter values for prepared statement placeholders
		 * @return StatementInterface|null Statement object on success, false on failure
		 */
		public function execute(string $query, array $parameters = []): ?StatementInterface {
			try {
				$this->deduplicateParameters($query, $parameters);
				return $this->connection->execute($query, $parameters);
			} catch (\Exception $exception) {
				$this->last_error = $exception->getCode();
				$this->last_error_message = $exception->getMessage();
				return null;
			}
		}
		
		/**
		 * Retrieves the auto-generated ID from the last INSERT operation
		 * @return int|string|false The last insert ID, or false if not available
		 */
		public function getInsertId(): int|string|false {
			return $this->connection->getDriver()->lastInsertId();
		}
		
		/**
		 * Escapes a database identifier (table or column name)
		 * @param string $identifier The identifier to escape
		 * @return string The escaped identifier wrapped in the driver's quote character
		 */
		public function escapeIdentifier(string $identifier): string {
			return $this->connection->getDriver()->quoteIdentifier($identifier);
		}
		
		// ==================== Error Handling ====================
		
		/**
		 * Returns the error code from the last failed query
		 * @return int Error code (0 indicates no error)
		 */
		public function getLastError(): int {
			return $this->last_error;
		}
		
		/**
		 * Returns the error message from the last failed query
		 * @return string Error message text (empty string indicates no error)
		 */
		public function getLastErrorMessage(): string {
			return $this->last_error_message;
		}
		
		// ==================== Transaction Management ====================
		
		/**
		 * Begins a new database transaction.
		 *
		 * Nesting is depth-counted, not savepoint-based: a nested
		 * rollbackTrans() marks the whole transaction rollback-only rather
		 * than rolling back immediately, so an outer commitTrans() still
		 * rolls back instead of silently committing.
		 *
		 * @return void
		 */
		public function beginTrans(): void {
			if ($this->transaction_depth == 0) {
				$this->connection->begin();
			}

			$this->transaction_depth++;
		}

		/**
		 * Commits the current transaction.
		 * See beginTrans() for notes on logical (depth-counted) nesting.
		 * @return void
		 * @throws \LogicException If called without a matching beginTrans()
		 * @throws \LogicException If a nested rollbackTrans() had already marked the transaction rollback-only — rolled back, not committed, before this throws
		 */
		public function commitTrans(): void {
			if ($this->transaction_depth <= 0) {
				throw new \LogicException('commitTrans() called without an active transaction');
			}

			$this->transaction_depth--;

			if ($this->transaction_depth == 0) {
				if ($this->transaction_rollback_only) {
					$this->transaction_rollback_only = false;
					$this->connection->rollback();
					throw new \LogicException('commitTrans() called on a transaction a nested rollbackTrans() had already marked rollback-only — the transaction was rolled back, not committed');
				}

				$this->connection->commit();
			}
		}

		/**
		 * Rolls back the current transaction.
		 * See beginTrans() for notes on logical (depth-counted) nesting.
		 * @return void
		 * @throws \LogicException If called without a matching beginTrans()
		 */
		public function rollbackTrans(): void {
			if ($this->transaction_depth <= 0) {
				throw new \LogicException('rollbackTrans() called without an active transaction');
			}

			$this->transaction_depth--;

			if ($this->transaction_depth == 0) {
				$this->transaction_rollback_only = false;
				$this->connection->rollback();
			} else {
				$this->transaction_rollback_only = true;
			}
		}
		
		// ==================== Helpers ====================
		
		/**
		 * Rewrites duplicate named parameters so PDO can bind them.
		 * @param string $sql The SQL query, modified in place
		 * @param array<int|string, mixed> $parameters The parameter bindings, expanded in place
		 * @return void
		 */
		private function deduplicateParameters(string &$sql, array &$parameters): void {
			// Track how many times each named parameter has been seen so far
			$seen = [];
			
			// The regex alternation is ordered so that string literals are consumed first
			// and never reach the callback as a match group — only bare :param placeholders do.
			// This prevents false positives like WHERE x = ':term' from being rewritten.
			$sql = preg_replace_callback(
				"/'[^']*'|\"[^\"]*\"|:([a-zA-Z_][a-zA-Z0-9_]*)/",
				function (array $match) use (&$seen, &$parameters): string {
					// No capture group means this was a string literal — return it unchanged
					if (!isset($match[1])) {
						return $match[0];
					}
					
					// Fetch the match
					$name = $match[1];
					
					// First occurrence — leave the placeholder as-is
					if (!isset($seen[$name])) {
						$seen[$name] = 1;
						return $match[0];
					}
					
					// Subsequent occurrence — rename to :name_2, :name_3, etc.
					// and copy the original value so the new placeholder gets bound
					$seen[$name]++;
					$newName = $name . '_' . $seen[$name];
					$parameters[$newName] = $parameters[$name];
					return ':' . $newName;
				},
				$sql
			) ?? $sql;
		}
	}