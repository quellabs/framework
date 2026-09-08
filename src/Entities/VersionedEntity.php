<?php

	namespace App\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;

	/**
	 * Fixture entity for integration coverage of @Orm\Version handling on
	 * `append`/`replace` (see AppendVersionInitializationTest,
	 * ReplaceVersionBumpTest) — no other entity in App\Entities carries a
	 * @Orm\Version column.
	 * @Orm\Table(name="versioned_entities")
	 */
	class VersionedEntity {

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
		 * @Orm\Column(name="version", type="integer")
		 * @Orm\Version
		 */
		protected int $version;

		public function getId(): ?int {
			return $this->id;
		}

		public function getLabel(): string {
			return $this->label;
		}

		public function setLabel(string $label): self {
			$this->label = $label;
			return $this;
		}

		public function getVersion(): int {
			return $this->version;
		}
	}
