<?php

namespace Quellabs\Recommender\Internal\Query;

use Quellabs\Recommender\Internal\Persistence\TemporaryTable;
use Quellabs\Recommender\Internal\Identifier;

/** Restricts a query to an allowlist of product IDs, using a temporary table for large lists. */
final class CandidateAllowlist {

	/** @var int Lists longer than this are loaded into a temporary table */
	private const TABLE_THRESHOLD = 500;

	/** @var string Temporary table holding a large allowlist for one query */
	private const TABLE = 'recommender_allowed_items';

	/** @var TemporaryTable Temporary table helper */
	private TemporaryTable $temporary;

	/**
	 * Build the allowlist helper.
	 * @param TemporaryTable $temporary Temporary table helper
	 */
	public function __construct(TemporaryTable $temporary) {
		$this->temporary = $temporary;
	}

	/**
	 * Build a parameterized predicate that keeps rows whose column is in the allowlist.
	 * Large lists are loaded into a temporary table, which release() must drop afterwards.
	 * @param array<int> $filter Allowed product IDs
	 * @param string $column SQL column selected by the caller
	 * @param array<string, int|float> $params Bound query parameters, extended in place
	 * @return string SQL predicate, empty when the filter is empty
	 * @throws \InvalidArgumentException When an allowed ID is not an unsigned 32-bit integer
	 */
	public function predicate(array $filter, string $column, array &$params): string {
		if ($filter === []) {
			return '';
		}

		foreach ($filter as $id) {
			if (!is_int($id) || $id < 0 || $id > Identifier::MAX) {
				throw new \InvalidArgumentException('Allowed product ID must be an unsigned 32-bit integer, got ' . var_export($id, true) . '.');
			}
		}

		if (count($filter) > self::TABLE_THRESHOLD) {
			$this->temporary->createIdTable(self::TABLE);

			try {
				$this->temporary->insertIds(self::TABLE, $filter);
			} catch (\Throwable $exception) {
				$this->temporary->dropTable(self::TABLE);
				throw $exception;
			}

			return ' AND ' . $column . ' IN (SELECT product_id FROM ' . self::TABLE . ')';
		}

		$names = [];

		foreach (array_values($filter) as $index => $id) {
			$name = 'allowed_' . $index;
			$params[$name] = $id;
			$names[] = ':' . $name;
		}

		return ' AND ' . $column . ' IN (' . implode(',', $names) . ')';
	}

	/**
	 * Drop the temporary table that predicate() created for a large allowlist.
	 * @param array<int> $filter The allowlist passed to predicate()
	 * @return void
	 */
	public function release(array $filter): void {
		if (count($filter) > self::TABLE_THRESHOLD) {
			$this->temporary->dropTable(self::TABLE);
		}
	}
}
