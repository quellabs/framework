<?php

	namespace App\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Compile-only fixture for QuelToSQLAppendDefaultColumnTest — declares a
	 * column with a declared annotation default so `append to` omitting it
	 * can be asserted to inject that default value rather than silently
	 * leaving the column out and relying on whatever DEFAULT (if any) the
	 * table's own DDL happens to declare. No test executes SQL against this
	 * entity, so it has no backing table.
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
	}
