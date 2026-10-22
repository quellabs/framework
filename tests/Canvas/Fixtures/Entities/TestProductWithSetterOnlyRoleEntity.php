<?php

	namespace Quellabs\Canvas\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Fixture with a setter whose name has no matching property. JSON:API
	 * validation must reject that setter-only attribute.
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
