<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;

	/**
	 * SQLite fulltext-index support: resolving the base table a SQLite FTS5
	 * external-content virtual table was built against. See
	 * objectquel-destroy-index-plan.md's "Fulltext index destroy on
	 * sqlsrv/sqlite" section.
	 */
	class SqliteFulltextIndexInspector {

		/**
		 * @var DatabaseAdapter
		 */
		private readonly DatabaseAdapter $adapter;

		/**
		 * @param DatabaseAdapter $adapter
		 */
		public function __construct(DatabaseAdapter $adapter) {
			$this->adapter = $adapter;
		}

		/**
		 * Returns the base table name a SQLite FTS5 external-content
		 * virtual table named $indexName was built against, or null if no
		 * such virtual table exists. The FTS5 table is an ordinary
		 * sqlite_master row (type='table') indistinguishable from any other
		 * table except by its own `CREATE VIRTUAL TABLE ... USING
		 * fts5(...)` text — parsed here for the `content=` option
		 * QuelToSQLCreateIndex::compileSqliteFulltext() always sets to the
		 * base table name.
		 * @param string $indexName
		 * @return string|null
		 */
		public function getFts5BaseTable(string $indexName): ?string {
			$statement = $this->adapter->execute(
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
	}
