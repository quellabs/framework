<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	/**
	 * Fallback for an engine getDatabaseType() cannot map to a concrete
	 * introspector. getDatabaseType() is presently a closed enumeration (see
	 * DatabaseAdapter::getDatabaseType()), so this exists purely as a
	 * defensive fallback for a future unmapped engine value — not a branch
	 * reachable today — matching the old dispatch match()'s 'default' arms.
	 */
	class NullSchemaIntrospector implements SchemaIntrospectorInterface {

		public function getColumns(string $tableName): array {
			return [];
		}

		public function getForeignKeys(string $tableName): array {
			return [];
		}

		public function getIndexUsageStatistics(array $tables): ?array {
			return null;
		}
	}
