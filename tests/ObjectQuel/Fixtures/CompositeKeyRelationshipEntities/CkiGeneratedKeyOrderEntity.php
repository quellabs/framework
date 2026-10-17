<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * Composite primary key (tenantId, token) where one component is assigned
	 * by the caller (tenantId, no @PrimaryKeyStrategy) and the other is
	 * generated independently ('uuid', stateless and collision-safe) —
	 * exercises AppendExecutor::fillGeneratedPrimaryKeys()'s per-component
	 * generation for a composite key.
	 * @Orm\Table(name="cki_generated_key_orders")
	 */
	class CkiGeneratedKeyOrderEntity {
		/**
		 * @Orm\Column(name="tenant_id", type="integer", primary_key=true)
		 */
		protected ?int $tenantId = null;

		/**
		 * @Orm\Column(name="token", type="string", limit=36, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="uuid")
		 */
		protected ?string $token = null;

		/**
		 * @Orm\Column(name="amount", type="integer")
		 */
		protected ?int $amount = null;

		public function getTenantId(): ?int {
			return $this->tenantId;
		}

		public function setTenantId(?int $tenantId): void {
			$this->tenantId = $tenantId;
		}

		public function getToken(): ?string {
			return $this->token;
		}

		public function getAmount(): ?int {
			return $this->amount;
		}

		public function setAmount(?int $amount): void {
			$this->amount = $amount;
		}
	}
