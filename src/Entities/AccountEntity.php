<?php
	
	namespace App\Entities;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	
	/**
	 * Inverse side of a OneToOne. Carries no FK column of its own — the owning
	 * CredentialEntity::$account holds it. $credential is declared with an InverseOf annotation
	 * so a via-clause can traverse from this side onto the owning OneToOne.
	 *
	 * @Orm\Table(name="accounts")
	 */
	class AccountEntity {
		
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;
		
		/**
		 * @Orm\Column(name="label", type="string", limit=255)
		 */
		protected string $label;
		
		/**
		 * Inverse side of CredentialEntity::$account (a OneToOne whose relation
		 * parameter is "account"). Scalar, not a collection.
		 *
		 * @Orm\InverseOf(targetEntity=CredentialEntity::class, relation="account")
		 */
		public ?CredentialEntity $credential = null;
		
		/**
		 * Get id
		 * @return int
		 */
		public function getId(): int {
			return $this->id;
		}
		
		/**
		 * Get label
		 * @return string
		 */
		public function getLabel(): string {
			return $this->label;
		}
		
		/**
		 * Set label
		 * @param string $label
		 * @return $this
		 */
		public function setLabel(string $label): self {
			$this->label = $label;
			return $this;
		}
	}