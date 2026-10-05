<?php

namespace Quellabs\Recommender\Internal\Persistence;

use Cake\Database\Connection;

/** Creates temporary tables that hold candidate sets and rating inputs for one query. */
final class TemporaryTable {

	/** @var int Rows per multi-row INSERT */
	private const CHUNK_SIZE = 500;

	/** @var Connection Database connection */
	private Connection $connection;

	/**
	 * Build the helper.
	 * @param Connection $connection The CakePHP database connection
	 */
	public function __construct(Connection $connection) {
		$this->connection = $connection;
	}

	/**
	 * Load distinct product IDs into a temporary table for the duration of one operation.
	 * @template T
	 * @param string $prefix Table name prefix, followed by a random suffix
	 * @param array<int, int> $ids Product IDs, duplicates allowed
	 * @param callable(string): T $operation Receives the table name
	 * @return T Operation result
	 */
	public function withIdTable(string $prefix, array $ids, callable $operation): mixed {
		return $this->scoped($prefix, 'product_id INT UNSIGNED PRIMARY KEY',
			fn(string $table) => $this->insertIds($table, $ids), $operation);
	}

	/**
	 * Load a product-to-rating map into a temporary table for the duration of one operation.
	 * @template T
	 * @param string $prefix Table name prefix, followed by a random suffix
	 * @param array<int, float> $ratings Rating per product ID
	 * @param callable(string): T $operation Receives the table name
	 * @return T Operation result
	 */
	public function withRatingTable(string $prefix, array $ratings, callable $operation): mixed {
		$rows = [];

		foreach ($ratings as $id => $rating) {
			$rows[] = [$id, $rating];
		}

		return $this->scoped($prefix, 'product_id INT UNSIGNED PRIMARY KEY, rating DOUBLE NOT NULL',
			fn(string $table) => $this->insertRows($table, ['product_id', 'rating'], $rows), $operation);
	}

	/**
	 * Load neighbour similarities into a temporary table for the duration of one operation.
	 * @template T
	 * @param string $prefix Table name prefix, followed by a random suffix
	 * @param array<int, array{member_id: int, similarity: int}> $neighbours Neighbours with their similarity
	 * @param callable(string): T $operation Receives the table name
	 * @return T Operation result
	 */
	public function withNeighbourTable(string $prefix, array $neighbours, callable $operation): mixed {
		$rows = array_map(fn($neighbour) => [$neighbour['member_id'], $neighbour['similarity']], $neighbours);

		return $this->scoped($prefix, 'member_id INT UNSIGNED PRIMARY KEY, similarity INT UNSIGNED NOT NULL',
			fn(string $table) => $this->insertRows($table, ['member_id', 'similarity'], $rows), $operation);
	}

	/**
	 * Create a named product ID table, replacing any table of the same name left by an earlier call.
	 * @param string $name Table name
	 * @return void
	 */
	public function createIdTable(string $name): void {
		$this->dropTable($name);
		$this->connection->execute("CREATE TEMPORARY TABLE {$name} (product_id INT UNSIGNED PRIMARY KEY)");
	}

	/**
	 * Insert distinct product IDs into an existing table in batches.
	 * @param string $table Table name
	 * @param array<int, int> $ids Product IDs, duplicates allowed
	 * @return void
	 */
	public function insertIds(string $table, array $ids): void {
		$rows = array_map(fn($id) => [$id], array_values(array_unique($ids)));
		$this->insertRows($table, ['product_id'], $rows);
	}

	/**
	 * Drop a temporary table if it exists.
	 * @param string $name Table name
	 * @return void
	 */
	public function dropTable(string $name): void {
		$this->connection->execute("DROP TEMPORARY TABLE IF EXISTS {$name}");
	}

	/**
	 * Create a uniquely named table, load it, run the operation, and drop the table afterwards.
	 * @template T
	 * @param string $prefix Table name prefix, followed by a random suffix
	 * @param string $definition Column definitions
	 * @param callable(string): void $load Fills the table with its rows
	 * @param callable(string): T $operation Receives the table name
	 * @return T Operation result
	 */
	private function scoped(string $prefix, string $definition, callable $load, callable $operation): mixed {
		$table = $prefix . bin2hex(random_bytes(6));
		$this->connection->execute("CREATE TEMPORARY TABLE {$table} ({$definition})");

		try {
			$load($table);
			return $operation($table);
		} finally {
			$this->connection->execute("DROP TEMPORARY TABLE {$table}");
		}
	}

	/**
	 * Insert rows as multi-row statements of at most CHUNK_SIZE rows.
	 * @param string $table Table name
	 * @param array<int, string> $columns Column names matching each row's values
	 * @param array<int, array<int, int|float|string>> $rows Row values in column order
	 * @return void
	 */
	private function insertRows(string $table, array $columns, array $rows): void {
		$holder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';

		foreach (array_chunk($rows, self::CHUNK_SIZE) as $batch) {
			$this->connection->execute(
				sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns),
					implode(',', array_fill(0, count($batch), $holder))),
				array_merge(...$batch)
			);
		}
	}
}
