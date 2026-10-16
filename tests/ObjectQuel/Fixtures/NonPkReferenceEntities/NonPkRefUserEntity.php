<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\NonPkReferenceEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * Target entity for NonPkRefProfileEntity's ManyToOne, which references the
	 * non-primary-key 'email' property instead of the primary key 'id'.
	 * @Orm\Table(name="non_pk_ref_users")
	 */
	class NonPkRefUserEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="email", type="string", limit=255)
		 */
		protected ?string $email = null;

		public function getId(): ?int {
			return $this->id;
		}

		public function getEmail(): ?string {
			return $this->email;
		}

		public function setEmail(?string $email): void {
			$this->email = $email;
		}
	}
