<?php

	namespace Quellabs\ObjectQuel\Execution\Helpers;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\CharacterClass;

	/**
	 * Translates a string literal pattern (unescaped *, ? and [...] classes) into LIKE, GLOB or regular expression syntax.
	 */
	final class WildcardPattern {

		/** Escape character in LIKE and regex forms; LIKE needs an explicit ESCAPE clause since SQLite and SQL Server have no default */
		public const string ESCAPE_CHAR = '\\';

		/** Characters LIKE treats as special; [ opens a class in T-SQL LIKE */
		private const string LIKE_SPECIAL_CHARS = '%_[\\';

		/** Characters escaped inside a bracket class, valid in T-SQL LIKE, ICU, PCRE and PostgreSQL regexes */
		private const string CLASS_SPECIAL_CHARS = '\\]^-[';

		/** Regex metacharacters escaped outside a class; / is included so the body also fits a /-delimited PHP pattern */
		private const string REGEX_SPECIAL_CHARS = '.^$|()[]{}*+?\\/';

		/**
		 * Builds a LIKE pattern: wildcards become % and _, each class becomes _; literal %, _, [ and \ are escaped.
		 * @param AstString $string String literal pattern
		 * @return string
		 */
		public static function toSqlLike(AstString $string): string {
			return self::translate(
				$string,
				['*' => '%', '?' => '_'],
				fn(string $byte): string => self::escape($byte, self::LIKE_SPECIAL_CHARS),
				fn(CharacterClass $class): string => '_'
			);
		}

		/**
		 * Builds a T-SQL LIKE pattern, which supports [...] classes natively; members are listed explicitly so ranges never depend on collation.
		 * @param AstString $string String literal pattern
		 * @return string
		 */
		public static function toSqlServerLike(AstString $string): string {
			return self::translate(
				$string,
				['*' => '%', '?' => '_'],
				fn(string $byte): string => self::escape($byte, self::LIKE_SPECIAL_CHARS),
				fn(CharacterClass $class): string => self::bracketClass($class->getCharacters())
			);
		}

		/**
		 * Builds an SQLite GLOB pattern; ASCII letters match both cases to mirror SQLite's case-insensitive LIKE.
		 * @param AstString $string String literal pattern
		 * @return string
		 */
		public static function toGlob(AstString $string): string {
			return self::translate(
				$string,
				['*' => '*', '?' => '?'],
				fn(string $byte): string => self::globLiteral($byte),
				fn(CharacterClass $class): string => self::globClass($class->getCharacters())
			);
		}

		/**
		 * Builds an unanchored regex body: * becomes .*, ? becomes ., classes list their members explicitly.
		 * @param AstString $string String literal pattern
		 * @return string
		 */
		public static function toRegexBody(AstString $string): string {
			return self::translate(
				$string,
				['*' => '.*', '?' => '.'],
				fn(string $byte): string => self::escape($byte, self::REGEX_SPECIAL_CHARS),
				fn(CharacterClass $class): string => self::bracketClass($class->getCharacters())
			);
		}

		/**
		 * Walks the value, translating wildcards, classes and literal bytes with the given mappings.
		 * @param AstString $string String literal pattern
		 * @param array<string, string> $wildcardMap Replacement for each wildcard character
		 * @param callable(string): string $literal Translates one literal byte
		 * @param callable(CharacterClass): string $class Translates one character class
		 * @return string
		 */
		private static function translate(AstString $string, array $wildcardMap, callable $literal, callable $class): string {
			$value = $string->getValue();
			$wildcardOffsets = array_flip($string->getWildcardOffsets());
			$classesByStart = [];

			foreach ($string->getCharacterClasses() as $characterClass) {
				$classesByStart[$characterClass->getStart()] = $characterClass;
			}

			$pattern = '';
			$length = strlen($value);
			$i = 0;

			while ($i < $length) {
				if (isset($classesByStart[$i])) {
					$pattern .= $class($classesByStart[$i]);
					$i = $classesByStart[$i]->getEnd();
				} elseif (isset($wildcardOffsets[$i])) {
					$pattern .= $wildcardMap[$value[$i]];
					$i++;
				} else {
					$pattern .= $literal($value[$i]);
					$i++;
				}
			}

			return $pattern;
		}

		/**
		 * Prefixes a single character with the escape character when it is special.
		 * @param string $character One character (multibyte characters are never special)
		 * @param string $specialChars Characters that need escaping
		 * @return string
		 */
		private static function escape(string $character, string $specialChars): string {
			if (strlen($character) === 1 && str_contains($specialChars, $character)) {
				return self::ESCAPE_CHAR . $character;
			}

			return $character;
		}

		/**
		 * Builds a [...] class with each special member backslash-escaped.
		 * @param list<string> $characters Members of the class
		 * @return string
		 */
		private static function bracketClass(array $characters): string {
			$members = '';

			foreach ($characters as $character) {
				$members .= self::escape($character, self::CLASS_SPECIAL_CHARS);
			}

			return '[' . $members . ']';
		}

		/**
		 * Translates one literal byte to GLOB, which has no escape character: special characters go in a one-member class.
		 * @param string $byte Literal byte
		 * @return string
		 */
		private static function globLiteral(string $byte): string {
			if ($byte === '*' || $byte === '?' || $byte === '[') {
				return '[' . $byte . ']';
			}

			if (strtolower($byte) !== strtoupper($byte)) {
				return '[' . strtolower($byte) . strtoupper($byte) . ']';
			}

			return $byte;
		}

		/**
		 * Builds a GLOB class: ] first and - next are literal there, and ^ must not come first or it negates.
		 * @param list<string> $characters Members of the class
		 * @return string
		 */
		private static function globClass(array $characters): string {
			$members = [];

			foreach ($characters as $character) {
				$members[] = strtolower($character);
				$members[] = strtoupper($character);
			}

			$members = array_values(array_unique($members));

			// A single member (only ^, ], - or a non-letter) is just that literal character
			if (count($members) === 1) {
				return self::globLiteral($members[0]);
			}

			$leading = array_values(array_intersect([']', '-'], $members));
			$others = array_diff($members, [']', '-', '^']);
			$trailing = in_array('^', $members, true) ? ['^'] : [];

			return '[' . implode('', array_merge($leading, $others, $trailing)) . ']';
		}
	}
