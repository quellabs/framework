<?php

	namespace App\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Two-column primary key (token, seq) where the 'sequence' strategy sits
	 * on the *second* component — regression coverage for
	 * SequenceGenerator::generate() reading the wrong column's MAX() when a
	 * sequence-strategy identifier isn't the first one declared (see
	 * PrimaryKeyGeneratorFixedTargetPropertyTest). 'uuid' on the first
	 * component keeps it out of the auto-increment slot, which only ever
	 * considers a single identifier column.
	 * @Orm\Table(name="sequence_component_entities")
	 */
	class SequenceComponentEntity {

		/**
		 * @Orm\Column(name="token", type="string", limit=36, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="uuid")
		 */
		protected ?string $token = null;

		/**
		 * @Orm\Column(name="seq", type="integer", primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="sequence")
		 */
		protected ?int $seq = null;

		/**
		 * @Orm\Column(name="amount", type="integer")
		 */
		protected ?int $amount = null;

		public function getToken(): ?string {
			return $this->token;
		}

		public function getSeq(): ?int {
			return $this->seq;
		}

		public function getAmount(): ?int {
			return $this->amount;
		}

		public function setAmount(?int $amount): void {
			$this->amount = $amount;
		}
	}
