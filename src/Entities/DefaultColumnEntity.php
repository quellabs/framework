<?php

	namespace App\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Fixture for QuelToSQLAppendDefaultColumnTest (compile-only, asserts on
	 * generated SQL) and AppendDefaultColumnTest (integration, backed by the
	 * real `default_column_test` table created in tests/bootstrap.php) —
	 * declares a column with a declared annotation default so `append to`
	 * omitting it can be asserted to inject that default value transparently
	 * rather than leaving the column out and falling through to the table's
	 * own DDL default (which the integration test deliberately sets to a
	 * different value, to prove the annotation — not the DDL — wins).
	 * @Orm\Table(name="default_column_test")
	 */
	class DefaultColumnEntity {

		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="name", type="string", limit=100)
		 */
		protected string $name;

		/**
		 * Deliberately a falsy declared default (0) — this is exactly the
		 * value Column::hasDefault()'s old `!empty()` check got wrong.
		 * @Orm\Column(name="priority", type="integer", default=0)
		 */
		protected int $priority = 0;

		public function getId(): ?int {
			return $this->id;
		}

		public function getName(): string {
			return $this->name;
		}

		public function getPriority(): int {
			return $this->priority;
		}
	}
