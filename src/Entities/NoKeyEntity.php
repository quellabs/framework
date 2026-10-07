<?php

	namespace App\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Deliberately has no mapped primary key — used to test code paths that require one
	 * (e.g. SqlServerEventAttachmentLowering's UPDATE row-pairing check).
	 * @Orm\Table(name="no_key_entities")
	 */
	class NoKeyEntity {

		/**
		 * @Orm\Column(name="label", type="string", limit=255)
		 */
		protected string $label;
	}
