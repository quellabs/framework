<?php

	namespace Quellabs\AnnotationReader;

	/**
	 * Process-wide registry of AnnotationReader instances, keyed by consumer (e.g.
	 * "canvas", "objectquel"). Each package gets its own cache path; nothing is shared
	 * implicitly. Mirrors SignalHubLocator.
	 */
	class AnnotationReaderLocator {

		/** @var array<string, AnnotationReader> Registered instances, keyed by consumer name */
		private static array $instances = [];

		/**
		 * Registers an AnnotationReader instance under the given consumer key,
		 * overwriting any instance previously registered under that same key.
		 * @param AnnotationReader $reader The reader instance to register
		 * @param string $key Consumer identifier (e.g. "canvas", "objectquel")
		 * @return void
		 */
		public static function setInstance(AnnotationReader $reader, string $key = 'default'): void {
			self::$instances[$key] = $reader;
		}

		/**
		 * Retrieves the AnnotationReader instance registered under the given consumer key.
		 * @param string $key Consumer identifier (e.g. "canvas", "objectquel")
		 * @return AnnotationReader|null The registered reader, or null if none has been registered under this key
		 */
		public static function getInstance(string $key = 'default'): ?AnnotationReader {
			return self::$instances[$key] ?? null;
		}

		/**
		 * Removes registered instance(s) from the registry. Intended for use
		 * between test cases to prevent state leaking across the process.
		 * @param string|null $key Clear only this key, or all instances when null
		 * @return void
		 */
		public static function reset(?string $key = null): void {
			if ($key === null) {
				self::$instances = [];
			} else {
				unset(self::$instances[$key]);
			}
		}
	}
