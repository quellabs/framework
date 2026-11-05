<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * Minimal fixture for EntitySchemaAnalyzerUnrecognizedColumnTypeTest. The
	 * entity only declares `id` — the table it's paired with in that test is
	 * created by hand with an extra column typed `DATETIME_TEXT`, a declared
	 * type this ORM's own DDL layer never emits (see
	 * TypeMapper::sqliteSqlColumnType()). getColumns() reads every existing
	 * column unconditionally before any entity-vs-schema diffing happens, so
	 * the entity not declaring that column is irrelevant to the repro — the
	 * crash happens purely from reading what's already on disk.
	 * @Orm\Table(name="unrecognized_column_type_fixture")
	 */
	class UnrecognizedColumnTypeEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @return int|null The row's identity value, or null before insert.
		 */
		public function getId(): ?int {
			return $this->id;
		}
	}
