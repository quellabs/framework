<?php

	namespace App\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Fixture entity with a natural string primary key (no auto-increment) —
	 * window-pagination's page filter must bind it as a string, never guess it
	 * into a numeric AstNumber literal.
	 * @Orm\Table(name="string_pk_entities")
	 */
	class StringPkEntity {
		/**
		 * @Orm\Column(name="code", type="string", limit=20, primary_key=true)
		 */
		protected ?string $code = null;

		/**
		 * @Orm\Column(name="position", type="integer")
		 */
		protected ?int $position = null;

		public function getCode(): ?string {
			return $this->code;
		}

		public function getPosition(): ?int {
			return $this->position;
		}
	}
