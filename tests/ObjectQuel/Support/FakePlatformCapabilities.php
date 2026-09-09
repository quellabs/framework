<?php

	namespace Quellabs\ObjectQuel\Tests\Support;

	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;

	/**
	 * A PlatformCapabilitiesInterface reporting a caller-chosen database type,
	 * for testing dialect-specific SQL generation (e.g. QuelToSQLCreate/
	 * QuelToSQLDestroy) without a live connection to every engine.
	 */
	class FakePlatformCapabilities extends NullPlatformCapabilities {

		public function __construct(private readonly string $databaseType) {
		}

		public function getDatabaseType(): string {
			return $this->databaseType;
		}

		public function supportsUnsignedIntegers(): bool {
			return in_array($this->databaseType, ['mysql', 'mariadb'], true);
		}

		public function supportsTransactionalDDL(): bool {
			return !in_array($this->databaseType, ['mysql', 'mariadb'], true);
		}

		public function supportsNamedForeignKeys(): bool {
			return $this->databaseType !== 'sqlite';
		}
	}
