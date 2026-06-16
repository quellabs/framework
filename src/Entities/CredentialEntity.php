<?php
	
	namespace App\Entities;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	
	/**
	 * Owning side of a OneToOne with AccountEntity.
	 *
	 * NOTE: $account intentionally OMITS localColumn so the FK column name is
	 * derived from the default convention (owning property name + "Id" ->
	 * "accountId" -> column "account_id"). This is the exact condition the
	 * inverse-path localColumn fix targets: when reached via AccountEntity's
	 * InverseOf, the default base must be the owning property ("account"),
	 * not the inverse via-clause property ("credential").
	 *
	 * @Orm\Table(name="credentials")
	 */
	class CredentialEntity {
		
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;
		
		/**
		 * @Orm\Column(name="secret", type="string", limit=255)
		 */
		protected string $secret;
		
		/**
		 * Owning OneToOne. No localColumn -> defaults to "accountId".
		 *
		 * @Orm\OneToOne(targetEntity=AccountEntity::class, referencedColumn="id", fetch="EAGER")
		 */
		public ?AccountEntity $account = null;
		
		/**
		 * Backing FK column for the OneToOne above (property "accountId" -> column "account_id").
		 *
		 * @Orm\Column(name="account_id", type="integer", nullable=true)
		 */
		protected ?int $accountId = null;
		
		/**
		 * Get id
		 * @return int
		 */
		public function getId(): int {
			return $this->id;
		}
		
		/**
		 * Get secret
		 * @return string
		 */
		public function getSecret(): string {
			return $this->secret;
		}
		
		/**
		 * Set secret
		 * @param string $secret
		 * @return $this
		 */
		public function setSecret(string $secret): self {
			$this->secret = $secret;
			return $this;
		}
		
		/**
		 * Get accountId
		 * @return int|null
		 */
		public function getAccountId(): ?int {
			return $this->accountId;
		}
		
		/**
		 * Set accountId
		 * @param int|null $accountId
		 * @return $this
		 */
		public function setAccountId(?int $accountId): self {
			$this->accountId = $accountId;
			return $this;
		}
	}