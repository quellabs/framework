<?php

	namespace Quellabs\ObjectQuel\Sculpt\Helpers;

	/**
	 * Escapes a raw string value for splicing into a single-quoted Quel
	 * string literal — the write-side inverse of Lexer.php's
	 * escapeMapSingleQuote, which *unescapes* `\'` -> `'` and `\\` -> `\`
	 * when parsing an already-written literal. Backslash must be escaped
	 * before quote: escaping the quote first would introduce a fresh
	 * backslash that the (already-finished) backslash pass would never see,
	 * leaving it unescaped in the final Quel text.
	 *
	 * This is layer one of two needed when QuelMigrationBuilder splices a
	 * literal (an enum value, a `backfill` default) into generated PHP
	 * source: the Quel text itself needs this escaping, and then the
	 * *whole* generated Quel statement needs ordinary PHP
	 * single-quoted-string escaping (addslashes()) when it's written into
	 * the migration file as a PHP string literal — the same two-layer
	 * problem MigrationCodeBuilder::execute()'s addslashes($segment['sql'])
	 * call solves today, just for raw SQL instead of Quel text.
	 */
	final class QuelLiteralEscaper {

		public static function escape(string $value): string {
			$value = str_replace('\\', '\\\\', $value);
			return str_replace("'", "\\'", $value);
		}
	}
