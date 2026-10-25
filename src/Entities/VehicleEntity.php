<?php

	namespace App\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\DiscriminatorColumn;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Test-support base entity for single-table-inheritance append coverage.
	 * The "vehicle_type" column is deliberately not mapped as an ordinary
	 * property — a discriminator column is written by persistence machinery
	 * alone. See CarEntity for the subclass side of the split.
	 * @Orm\Table(name="sti_vehicles")
	 * @Orm\DiscriminatorColumn(name="vehicle_type")
	 */
	class VehicleEntity {

		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="name", type="string", limit=100)
		 */
		protected string $name;

		public function getId(): ?int {
			return $this->id;
		}
	}
