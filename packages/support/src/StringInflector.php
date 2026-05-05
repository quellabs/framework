<?php
	
	/**
	 * StringInflector - A string utility class
	 */
	
	namespace Quellabs\Support;
	
	class StringInflector {
		
		/**
		 * Words that don't change between singular and plural forms
		 */
		private static array $uncountable = [
			'equipment', 'information', 'rice', 'money', 'species', 'series',
			'fish', 'sheep', 'deer', 'aircraft', 'offspring', 'police',
			'staff', 'advice', 'furniture', 'luggage', 'news', 'software',
			'water', 'air', 'milk', 'sand', 'sugar', 'traffic', 'music',
			'art', 'love', 'happiness', 'knowledge', 'research'
		];
		
		/**
		 * Cached reverse lookup of $irregular (plural => singular)
		 */
		private static ?array $irregularFlipped = null;

		/**
		 * Returns the flipped $irregular array, computing it once and caching it.
		 */
		private static function getIrregularFlipped(): array {
			return self::$irregularFlipped ??= array_flip(self::$irregular);
		}

		/**
		 * Irregular singular to plural mappings
		 */
		private static array $irregular = [
			'man'        => 'men',
			'woman'      => 'women',
			'child'      => 'children',
			'tooth'      => 'teeth',
			'foot'       => 'feet',
			'mouse'      => 'mice',
			'person'     => 'people',
			'goose'      => 'geese',
			'ox'         => 'oxen',
			'datum'      => 'data',
			'medium'     => 'media',
			'criterion'  => 'criteria',
			'phenomenon' => 'phenomena',
			'index'      => 'indices',
			'matrix'     => 'matrices',
			'vertex'     => 'vertices',
			'cactus'     => 'cacti',
			'focus'      => 'foci',
			'fungus'     => 'fungi',
			'nucleus'    => 'nuclei',
			'stimulus'   => 'stimuli',
			'analysis'   => 'analyses',
			'basis'      => 'bases',
			'crisis'     => 'crises',
			'diagnosis'  => 'diagnoses',
			'hypothesis' => 'hypotheses',
			'oasis'      => 'oases',
			'synopsis'   => 'synopses',
			'thesis'     => 'theses',
			
			// Consonant-o loanwords that take plain -s, not -es
			'hello'      => 'hellos',
			'silo'       => 'silos',
			'piano'      => 'pianos',
			'photo'      => 'photos',
			'memo'       => 'memos',
			'logo'       => 'logos',
			'solo'       => 'solos',
			'pro'        => 'pros',
			'dynamo'     => 'dynamos',
			'casino'     => 'casinos',
			'volcano'    => 'volcanoes',  // keeps -es (native English)
			'tornado'    => 'tornadoes',  // keeps -es (native English)
			
			// f/fe -> ves irregulars (avoids the ambiguous /ves/ -> 'f' vs 'fe' problem)
			'knife'      => 'knives',
			'life'       => 'lives',
			'wife'       => 'wives',
			'leaf'       => 'leaves',
			'loaf'       => 'loaves',
			'thief'      => 'thieves',
			'shelf'      => 'shelves',
			'self'       => 'selves',
			'wolf'       => 'wolves',
			'elf'        => 'elves',
			'calf'       => 'calves',
			'half'       => 'halves',
			'scarf'      => 'scarves',

			// f -> ves words where the fallback /ves$/ -> 'fe' would be wrong
			// (these end in plain 'f', not 'fe', so they need explicit entries)
			'hoof'       => 'hooves',
			'roof'       => 'roofs',    // 'rooves' is archaic; 'roofs' is standard
			'proof'      => 'proofs',
			'belief'     => 'beliefs',
			'chief'      => 'chiefs',
			'grief'      => 'griefs',

			// Common -um words that should not follow the Latin datum->data pattern
			'album'      => 'albums',
			'forum'      => 'forums',
			'museum'     => 'museums',
			'stadium'    => 'stadiums', // 'stadia' exists but is rare in modern English
			'aquarium'   => 'aquariums',
			'auditorium' => 'auditoriums',
		];
		
		/**
		 * Pluralization rules (regex pattern => replacement)
		 * Ordered from most specific to most general
		 *
		 * Note: f/fe -> ves words are handled via $irregular to avoid the
		 * ambiguity between knife->knif vs wolf->wolfe with a single rule.
		 * The /ves$/ singularization fallback uses 'fe' as a default, so any
		 * word ending in plain 'f' (hoof, roof, belief) must be in $irregular.
		 *
		 * Note: Latin -um -> -a is NOT applied as a general rule because it
		 * breaks common English words (album, forum, museum). Only datum/medium
		 * are handled, via $irregular.
		 */
		private static array $pluralRules = [
			'/([^aeiou])y$/i'            => '$1ies',    // city -> cities
			'/(x|ch|sh|ss|z)$/i'        => '$1es',      // box -> boxes, church -> churches
			'/([aeiou])o$/i'             => '$1os',      // radio -> radios
			'/oo$/i'                     => 'oos',       // zoo -> zoos, igloo -> igloos
			'/([^aeiou])o$/i'            => '$1oes',     // hero -> heroes, potato -> potatoes
			'/(us)$/i'                   => '$1es',      // campus -> campuses, status -> statuses
			'/(analys|bas|cris|diagnos|hypothes|oas|synops|thes)is$/i' => '$1es', // known -is -> -es words

			// Specific Latin -on endings only — not common English words ending in -on
			'/(crit|phenomen|automat|polyhedr|axi)on$/i' => '$1a',
			'/(eau)$/i'                  => '$1x',       // tableau -> tableaux

			// Default rule
			'/$/i'                       => 's'          // cat -> cats
		];
		
		/**
		 * Singularization rules (regex pattern => replacement)
		 * Ordered from most specific to most general
		 *
		 * Note: -ves words are handled via $irregular (reverse lookup) to avoid
		 * the knife->knif vs wolf->wolfe ambiguity. The /ves$/ rule below is a
		 * fallback for unlisted words and uses 'fe' as the safer default.
		 */
		private static array $singularRules = [
			'/ies$/i'                    => 'y',         // cities -> city
			'/(x|ch|sh|ss|z)es$/i'      => '$1',        // boxes -> box

			// Note: 'halves -> half' is handled via $irregular reverse lookup.
			// There is no /alves$/ rule here because it would incorrectly affect
			// words like 'salves' (salve, not salf). /ves$/ -> 'fe' is the fallback.
			'/ves$/i'                    => 'fe',        // knives -> knife, lives -> life (safer default than 'f')
			'/([aeiou])ys$/i'            => '$1y',        // boys -> boy
			'/([aeiou])os$/i'            => '$1o',        // radios -> radio
			'/oos$/i'                    => 'oo',         // zoos -> zoo, igloos -> igloo
			'/([^aeiou])oes$/i'          => '$1o',        // heroes -> hero
			'/(us)es$/i'                 => '$1',         // campuses -> campus
			'/(analys|bas|cris|diagnos|hypothes|oas|synops|thes)es$/i' => '$1is', // analyses -> analysis etc.

			// Specific Latin -a endings only
			'/(crit|phenomen|automat|polyhedr|axi)a$/i'  => '$1on',
			'/(eau)x$/i'                 => '$1',         // tableaux -> tableau

			// Default rule
			'/s$/i'                      => ''            // cats -> cat
		];
		
		/**
		 * Convert a singular word to its plural form
		 * @param string $word The singular word to pluralize
		 * @return string The pluralized word
		 */
		public static function pluralize(string $word): string {
			$word = trim($word);
			
			// No word passed
			if (empty($word)) {
				return $word;
			}
			
			// Make lowercase
			$lowercaseWord = strtolower($word);
			
			// Check if the word is uncountable
			if (in_array($lowercaseWord, self::$uncountable, true)) {
				return $word;
			}
			
			// Check for irregular forms
			if (isset(self::$irregular[$lowercaseWord])) {
				return self::preserveCase($word, self::$irregular[$lowercaseWord]);
			}
			
			// Apply pluralization rules
			foreach (self::$pluralRules as $pattern => $replacement) {
				$result = preg_replace($pattern, $replacement, $word, 1, $count);
				
				if ($count > 0) {
					return $result;
				}
			}
			
			return $word;
		}
		
		/**
		 * Convert a plural word to its singular form
		 * @param string $word The plural word to singularize
		 * @return string The singularized word
		 */
		public static function singularize(string $word): string {
			$word = trim($word);
			
			// No word passed
			if (empty($word)) {
				return $word;
			}
			
			// Make lowercase
			$lowercaseWord = strtolower($word);
			
			// Check if the word is uncountable
			if (in_array($lowercaseWord, self::$uncountable, true)) {
				return $word;
			}
			
			// Check for irregular forms (reverse lookup)
			$irregularFlipped = self::getIrregularFlipped();
			
			if (isset($irregularFlipped[$lowercaseWord])) {
				return self::preserveCase($word, $irregularFlipped[$lowercaseWord]);
			}
			
			// Apply singularization rules
			foreach (self::$singularRules as $pattern => $replacement) {
				$result = preg_replace($pattern, $replacement, $word, 1, $count);
				
				if ($count > 0) {
					return $result;
				}
			}
			
			return $word;
		}
		
		/**
		 * Check if a word is likely plural.
		 * Note: this is a heuristic and not 100% reliable for all edge cases
		 * (e.g. "gas", "bus" may produce unexpected results).
		 * @param string $word The word to check
		 * @return bool True if the word appears to be plural
		 */
		public static function isPlural(string $word): bool {
			$word = trim($word);
			$lowercaseWord = strtolower($word);
			
			// No word passed
			if (empty($word)) {
				return false;
			}
			
			// Check if it's uncountable (neither singular nor plural)
			if (in_array($lowercaseWord, self::$uncountable, true)) {
				return false;
			}
			
			// Check if it's a known irregular plural
			$irregularFlipped = self::getIrregularFlipped();
			if (isset($irregularFlipped[$lowercaseWord])) {
				return true;
			}
			
			// Check if it's a known irregular singular
			if (isset(self::$irregular[$lowercaseWord])) {
				return false;
			}
			
			// Apply some heuristics for common plural patterns
			return
				strlen($word) > 3 &&
				preg_match('/(ies|ves|oes|es|s)$/i', $word) &&
				!preg_match('/(ss|us|is)$/i', $word);
		}
		
		/**
		 * Check if a word is likely singular.
		 * Note: this is a heuristic — see isPlural() for caveats.
		 * @param string $word The word to check
		 * @return bool True if the word appears to be singular
		 */
		public static function isSingular(string $word): bool {
			$lowercaseWord = strtolower($word);
			
			// Check if it's uncountable (neither singular nor plural)
			if (in_array($lowercaseWord, self::$uncountable, true)) {
				return false;
			}
			
			return !self::isPlural($word);
		}
		
		/**
		 * Converts camelCase or PascalCase string to snake_case
		 * @param string $string String to convert
		 * @return string Lowercase snake_case string
		 */
		public static function snakeCase(string $string): string {
			return strtolower(preg_replace(['/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/'], '$1_$2', $string));
		}
		
		/**
		 * Converts snake_case to camelCase format.
		 * @param string $snakeStr The snake_case string to convert
		 * @return string The converted camelCase string
		 */
		public static function camelCase(string $snakeStr): string {
			// Split the string by underscores to get individual words
			$words = explode('_', $snakeStr);
			
			// Keep the first word lowercase, capitalize the first letter of remaining words
			return $words[0] . implode('', array_map('ucfirst', array_slice($words, 1)));
		}
		
		/**
		 * Preserve the case pattern of the original word in the result
		 * @param string $original The original word with the desired case pattern
		 * @param string $transformed The transformed word to apply the case pattern to
		 * @return string The transformed word with preserved case
		 */
		private static function preserveCase(string $original, string $transformed): string {
			// All uppercase
			if (ctype_upper($original)) {
				return strtoupper($transformed);
			}
			
			// First letter uppercase
			if (ctype_upper($original[0])) {
				return ucfirst(strtolower($transformed));
			}
			
			// All lowercase or mixed case - return as is
			return $transformed;
		}
	}