<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\NonPkReferenceEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * Owning side of a lazy ManyToOne whose referencedColumn ('email') is not
	 * NonPkRefUserEntity's primary key — regression coverage for the lazy-proxy
	 * path that must resolve by that property instead of (wrongly) treating
	 * its value as a primary key lookup.
	 * @Orm\Table(name="non_pk_ref_profiles")
	 */
	class NonPkRefProfileEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=NonPkRefUserEntity::class, referencedColumn="email", localColumn="userEmail", fetch="LAZY")
		 */
		public ?NonPkRefUserEntity $user = null;

		/**
		 * @Orm\Column(name="user_email", type="string", limit=255)
		 */
		protected ?string $userEmail = null;

		public function getId(): ?int {
			return $this->id;
		}

		public function setUserEmail(?string $userEmail): void {
			$this->userEmail = $userEmail;
		}
	}
