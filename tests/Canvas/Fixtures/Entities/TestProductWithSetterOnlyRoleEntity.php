<?php

	namespace Quellabs\Canvas\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Fixture entity whose setRole() writes a differently named property
	 * ($roleInternal) with no backing "role" property at all. Exists solely
	 * to regression-test that a request key submitted with different casing
	 * (e.g. "ROLE") cannot bypass isAttributeWritable() just because PHP's
	 * method lookup finds setRole() case-insensitively.
	 * @Orm\Table(name="test_products_with_setter_only_role")
	 */
	class TestProductWithSetterOnlyRoleEntity {

		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="role_internal", type="string", limit=50)
		 */
		protected string $roleInternal = 'user';

		/**
		 * Returns the product's identifier.
		 * @return int|null
		 */
		public function getId(): ?int {
			return $this->id;
		}

		/**
		 * Returns the backing role value.
		 * @return string
		 */
		public function getRoleInternal(): string {
			return $this->roleInternal;
		}

		/**
		 * Setter-only attribute "role": writes $roleInternal, with no backing "role" property.
		 * @param string $role
		 * @return void
		 */
		public function setRole(string $role): void {
			$this->roleInternal = $role;
		}
	}
