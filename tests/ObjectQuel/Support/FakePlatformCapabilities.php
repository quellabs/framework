<?php

	namespace Quellabs\ObjectQuel\Tests\Support;

	use Quellabs\ObjectQuel\Capabilities\FulltextIndexStyle;
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

		public function supportsNativeEnums(): bool {
			return in_array($this->databaseType, ['mysql', 'mariadb'], true);
		}

		public function supportsTransactionalDDL(): bool {
			return !in_array($this->databaseType, ['mysql', 'mariadb'], true);
		}

		public function supportsNamedForeignKeys(): bool {
			return $this->databaseType !== 'sqlite';
		}

		public function supportsIndexHiding(): bool {
			return in_array($this->databaseType, ['mysql', 'mariadb'], true);
		}

		public function getFulltextIndexStyle(): FulltextIndexStyle {
			return match ($this->databaseType) {
				'sqlite' => FulltextIndexStyle::Fts5,
				'pgsql' => FulltextIndexStyle::Tsvector,
				default => FulltextIndexStyle::Fulltext,
			};
		}

		public function supportsQualifiedSetTarget(): bool {
			return !in_array($this->databaseType, ['pgsql', 'sqlite'], true);
		}

		/**
		 * Mirrors PlatformCapabilities::getDatetimeFromUnixTimestamp().
		 * @param string $timestampSql SQL of the Unix timestamp
		 * @return string
		 */
		public function getDatetimeFromUnixTimestamp(string $timestampSql): string {
			return match ($this->databaseType) {
				'pgsql' => "(TO_TIMESTAMP({$timestampSql}) AT TIME ZONE 'UTC')",
				'sqlite' => "datetime({$timestampSql}, 'unixepoch')",
				'sqlsrv' => "DATEADD(SECOND, CAST({$timestampSql} AS BIGINT) % 86400, DATEADD(DAY, CAST({$timestampSql} AS BIGINT) / 86400, CAST('1970-01-01' AS DATETIME2)))",
				default => "FROM_UNIXTIME({$timestampSql})",
			};
		}
	}
