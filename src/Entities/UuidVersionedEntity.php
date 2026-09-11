<?php

	namespace App\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;

	/**
	 * Fixture entity for integration coverage of a uuid-typed @Orm\Version
	 * column (see WriteVerbVersionColumnTest) — the uuid strategy is the one
	 * case where bumping the version column on `replace` requires adding a
	 * brand-new bound parameter to the statement (see
	 * VersionValueHandler::buildVersionSetClause()'s uuid branch), which is
	 * exactly the case that exposed ReplaceExecutor::compileSql()'s former
	 * by-value $parameters bug (added parameters were silently dropped
	 * before execution). App\Entities\VersionedEntity's integer version
	 * column doesn't exercise that path (`col = col + 1` needs no new
	 * parameter), hence this separate fixture.
	 * @Orm\Table(name="uuid_versioned_entities")
	 */
	class UuidVersionedEntity {

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
		 * @Orm\Column(name="token", type="uuid", limit=36)
		 * @Orm\Version
		 */
		protected string $token;

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

		public function getToken(): string {
			return $this->token;
		}
	}
