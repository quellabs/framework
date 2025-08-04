<?php
	
	/**
	 * StringInflector - A utility class for pluralization and singularization of English words
	 *
	 * This class provides static methods to convert English words between singular and plural forms.
	 * It handles most common English pluralization rules including irregular words, uncountable nouns,
	 * and various suffix patterns.
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
			'thesis'     => 'theses'
		];
		
		/**
		 * Pluralization rules (regex pattern => replacement)
		 * Ordered from most specific to most general
		 */
		private static array $pluralRules = [
			// Irregular endings
			'/([^aeiou])y$/i'    => '$1ies',        // city -> cities
			'/(x|ch|sh|ss|z)$/i' => '$1es',         // box -> boxes, church -> churches
			'/([^f])fe?$/i'      => '$1ves',        // knife -> knives, life -> lives
			'/alf$/i'            => 'alves',        // half -> halves
			'/([aeiou])y$/i'     => '$1ys',         // boy -> boys
			'/([aeiou])o$/i'     => '$1os',         // radio -> radios
			'/([^aeiou])o$/i'    => '$1oes',        // hero -> heroes
			'/(us)$/i'           => '$1es',         // campus -> campuses
			'/(is)$/i'           => 'es',           // analysis -> analyses
			'/(on)$/i'           => 'a',            // criterion -> criteria
			'/um$/i'             => 'a',            // datum -> data
			'/(eau)$/i'          => '$1x',          // tableau -> tableaux
			
			// Default rule
			'/$/i'               => 's'             // cat -> cats
		];
		
		/**
		 * Singularization rules (regex pattern => replacement)
		 * Ordered from most specific to most general
		 */
		private static array $singularRules = [
			// Irregular endings
			'/ies$/i'              => 'y',                       // cities -> city
			'/(x|ch|sh|ss|z)es$/i' => '$1',         // boxes -> box, churches -> church
			'/ves$/i'              => 'f',                       // knives -> knife
			'/alves$/i'            => 'alf',                   // halves -> half
			'/([aeiou])ys$/i'      => '$1y',             // boys -> boy
			'/([aeiou])os$/i'      => '$1o',             // radios -> radio
			'/([^aeiou])oes$/i'    => '$1o',           // heroes -> hero
			'/(us)es$/i'           => '$1',                   // campuses -> campus
			'/es$/i'               => 'is',                       // analyses -> analysis
			'/ia$/i'               => 'ion',                      // criteria -> criterion
			'/ta$/i'               => 'tum',                      // data -> datum
			'/(eau)x$/i'           => '$1',                   // tableaux -> tableau
			
			// Default rule
			'/s$/i'                => ''                           // cats -> cat
		];
		
		/**
		 * Convert a singular word to its plural form
		 *
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
			if (in_array($lowercaseWord, self::$uncountable)) {
				return $word;
			}
			
			// Check for irregular forms
			if (isset(self::$irregular[$lowercaseWord])) {
				return self::preserveCase($word, self::$irregular[$lowercaseWord]);
			}
			
			// Apply pluralization rules
			foreach (self::$pluralRules as $pattern => $replacement) {
				if (preg_match($pattern, $word)) {
					return preg_replace($pattern, $replacement, $word);
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
			if (in_array($lowercaseWord, self::$uncountable)) {
				return $word;
			}
			
			// Check for irregular forms (reverse lookup)
			$irregularFlipped = array_flip(self::$irregular);
			if (isset($irregularFlipped[$lowercaseWord])) {
				return self::preserveCase($word, $irregularFlipped[$lowercaseWord]);
			}
			
			// Apply singularization rules
			foreach (self::$singularRules as $pattern => $replacement) {
				if (preg_match($pattern, $word)) {
					return preg_replace($pattern, $replacement, $word);
				}
			}
			
			return $word;
		}
		
		/**
		 * Check if a word is likely plural
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
			if (in_array($lowercaseWord, self::$uncountable)) {
				return false;
			}
			
			// Check if it's a known irregular plural
			$irregularFlipped = array_flip(self::$irregular);
			if (isset($irregularFlipped[$lowercaseWord])) {
				return true;
			}
			
			// Check if it's a known irregular singular
			if (isset(self::$irregular[$lowercaseWord])) {
				return false;
			}
			
			// Apply some heuristics for common plural patterns
			return
				preg_match('/(ies|ves|oes|es|s)$/i', $word) &&
				!preg_match('/(ss|us|is)$/i', $word);
		}
		
		/**
		 * Check if a word is likely singular
		 * @param string $word The word to check
		 * @return bool True if the word appears to be singular
		 */
		public static function isSingular(string $word): bool {
			$lowercaseWord = strtolower($word);
			
			// Check if it's uncountable (neither singular nor plural)
			if (in_array($lowercaseWord, self::$uncountable)) {
				return false;
			}
			
			return !self::isPlural($word);
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