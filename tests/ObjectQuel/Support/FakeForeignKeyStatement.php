<?php

	namespace Quellabs\ObjectQuel\Tests\Support;

	use ArrayIterator;
	use Cake\Database\StatementInterface;
	use Iterator;
	use PDO;

	/**
	 * Minimal StatementInterface stub that only implements fetchAll() with
	 * canned rows, for testing DatabaseAdapter's row-parsing logic against
	 * engines (PostgreSQL, SQL Server) whose PDO drivers aren't installed in
	 * this environment — DatabaseAdapter::execute() is mocked to return one of
	 * these instead of ever opening a real connection.
	 */
	class FakeForeignKeyStatement implements StatementInterface {

		/** @param array<int, array<string, mixed>> $rows */
		public function __construct(private readonly array $rows) {
		}

		public function fetchAll(string|int $mode = PDO::FETCH_NUM): array {
			return $this->rows;
		}

		public function getIterator(): Iterator {
			return new ArrayIterator($this->rows);
		}

		public function bindValue(string|int $column, mixed $value, string|int|null $type = 'string'): void {
		}

		public function closeCursor(): void {
		}

		public function columnCount(): int {
			return 0;
		}

		public function errorCode(): string {
			return '';
		}

		public function errorInfo(): array {
			return [];
		}

		public function execute(?array $params = null): bool {
			return true;
		}

		public function fetch(string|int $mode = PDO::FETCH_NUM): mixed {
			return false;
		}

		public function fetchColumn(int $position): mixed {
			return false;
		}

		public function fetchAssoc(): array {
			return [];
		}

		public function rowCount(): int {
			return count($this->rows);
		}

		public function bind(array $params, array $types): void {
		}

		public function lastInsertId(?string $table = null, ?string $column = null): string|int {
			return 0;
		}

		public function queryString(): string {
			return '';
		}

		public function getBoundParams(): array {
			return [];
		}
	}
