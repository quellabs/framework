<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Derives the canonical name for a column's DEFAULT constraint —
	 * `df_{table}_{column}` — needed only on SQL Server, where `ADD COLUMN
	 * ... DEFAULT ...` creates a named constraint object that must be
	 * addressed by name to drop afterward (unlike MySQL/PostgreSQL, where
	 * `DROP DEFAULT` targets the column directly). See QuelToSQLAlter's
	 * `backfill` compilation and PlatformCapabilitiesInterface::
	 * supportsNamedForeignKeys() for the same platform-conditional-naming
	 * pattern applied to foreign keys.
	 *
	 * Never author-supplied — always derived, same decision
	 * ForeignKeyConstraintNamer makes for foreign key constraint names.
	 */
	class DefaultConstraintNamer {

		/**
		 * Tightest identifier-length limit shared by every supported
		 * dialect (see ForeignKeyConstraintNamer::MAX_LENGTH) — applied here
		 * too even though this namer is only ever invoked on SQL Server
		 * (128-character limit), for the same "one rule, not a per-dialect
		 * split" reasoning as the foreign key namer.
		 */
		private const int MAX_LENGTH = 63;

		public static function name(string $tableName, string $column): string {
			return 'df_' . $tableName . '_' . $column;
		}

		/**
		 * Same as name(), but throws when the derived identifier would
		 * exceed the length limit above — a clear compile-time
		 * QuelException instead of a confusing "identifier name is too
		 * long" failure from the database.
		 * @throws QuelException
		 */
		public static function nameOrThrow(string $tableName, string $column): string {
			$name = self::name($tableName, $column);

			if (strlen($name) > self::MAX_LENGTH) {
				throw new QuelException(
					"Cannot derive a default constraint name for '{$tableName}.{$column}': " .
					"'{$name}' is " . strlen($name) . " characters, exceeding the " . self::MAX_LENGTH .
					"-character identifier limit shared by every supported dialect — rename the table or column",
					'default_constraint_name_too_long'
				);
			}

			return $name;
		}
	}
