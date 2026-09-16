<?php

	namespace Quellabs\AnnotationReader;

	/**
	 * Process-wide registry of AnnotationReader instances, keyed by consumer (e.g.
	 * "canvas", "objectquel"). Each package gets its own cache path; nothing is shared
	 * implicitly. Mirrors SignalHubLocator.
	 */
	class AnnotationReaderLocator {

		/** @var array<string, AnnotationReader> */
		private static array $instances = [];

		public static function setInstance(AnnotationReader $reader, string $key = 'default'): void {
			self::$instances[$key] = $reader;
		}

		public static function getInstance(string $key = 'default'): ?AnnotationReader {
			return self::$instances[$key] ?? null;
		}

		/** @param string|null $key Clear only this key, or all instances when null */
		public static function reset(?string $key = null): void {
			if ($key === null) {
				self::$instances = [];
			} else {
				unset(self::$instances[$key]);
			}
		}
	}
