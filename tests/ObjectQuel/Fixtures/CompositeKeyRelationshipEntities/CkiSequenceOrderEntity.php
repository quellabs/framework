<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * Composite primary key (groupId, seq) whose *second* component declares
	 * the 'sequence' strategy — used only to prove AppendExecutor rejects a
	 * multi-row append for a 'sequence' component regardless of its position
	 * among the key's components, not just when it's the first one.
	 * @Orm\Table(name="cki_sequence_orders")
	 */
	class CkiSequenceOrderEntity {
		/**
		 * @Orm\Column(name="group_id", type="integer", primary_key=true)
		 */
		protected ?int $groupId = null;

		/**
		 * @Orm\Column(name="seq", type="integer", primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="sequence")
		 */
		protected ?int $seq = null;
	}
