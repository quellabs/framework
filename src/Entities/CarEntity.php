<?php

	namespace App\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\DiscriminatorValue;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * STI subclass of VehicleEntity. Inherits its columns unchanged. The
	 * table annotation is redeclared (class-level annotations aren't
	 * inherited, unlike property annotations, so EntityMetadataBuilder
	 * needs every entity to carry its own). The discriminator-column
	 * annotation is deliberately left off this class — it stays on
	 * VehicleEntity only, exercising DiscriminatorInfoResolver's
	 * parent-class walk.
	 * @Orm\Table(name="sti_vehicles")
	 * @Orm\DiscriminatorValue(value="car")
	 */
	class CarEntity extends VehicleEntity {
	}
