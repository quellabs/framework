<?php

	namespace Quellabs\ObjectQuel\Tests\Support;

	use ArrayIterator;
	use Cake\Database\StatementInterface;
	use Iterator;
	use PDO;

	/**
	 * Minimal StatementInterface stub returning a single canned fetchAssoc()
	 * row (or none), for testing DatabaseAdapter methods that read exactly
	 * one row without a real connection — see FakeForeignKeyStatement for
	 * the fetchAll()-based equivalent.
	 */
	class FakeRowStatement implements StatementInterface {

		/** @param array<string, mixed>|null $row Null simulates no matching row */
		public function __construct(private readonly ?array $row) {
		}

		public function fetchAssoc(): array {
			return $this->row ?? [];
		}

		public function fetchAll(string|int $mode = PDO::FETCH_NUM): array {
			return $this->row === null ? [] : [$this->row];
		}

		public function getIterator(): Iterator {
			return new ArrayIterator($this->fetchAll());
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
			return $this->row ?? false;
		}

		public function fetchColumn(int $position): mixed {
			return false;
		}

		public function rowCount(): int {
			return $this->row === null ? 0 : 1;
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
