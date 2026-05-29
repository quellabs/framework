<?php
	
	namespace Quellabs\AnnotationReader;
	
	/**
	 * Process-wide locator for the shared AnnotationReader instance.
	 *
	 * This locator allows packages that cannot depend on Canvas (e.g. ObjectQuel)
	 * to access the application's shared AnnotationReader without creating their
	 * own. Canvas registers its reader at boot via setInstance(); ObjectQuel
	 * retrieves it via getInstance(), falling back to null when running standalone.
	 *
	 * The pattern mirrors SignalHubLocator: a static singleton with no package
	 * dependencies beyond quellabs/annotation-reader, which both Canvas and
	 * ObjectQuel already require.
	 */
	class AnnotationReaderLocator {
		
		/**
		 * The shared AnnotationReader instance, or null if none has been registered.
		 * @var AnnotationReader|null
		 */
		private static ?AnnotationReader $instance = null;
		
		/**
		 * Register the application's shared AnnotationReader.
		 * Called once during Canvas kernel boot so all packages share one instance
		 * and one warm in-memory cache for the lifetime of the request.
		 * @param AnnotationReader $reader
		 * @return void
		 */
		public static function setInstance(AnnotationReader $reader): void {
			self::$instance = $reader;
		}
		
		/**
		 * Retrieve the shared AnnotationReader, or null if none has been registered.
		 * Callers that require a reader when running standalone should fall back to
		 * constructing their own instance when this returns null.
		 * @return AnnotationReader|null
		 */
		public static function getInstance(): ?AnnotationReader {
			return self::$instance;
		}
		
		/**
		 * Clear the registered instance.
		 * Intended for use in tests that need a clean state between runs.
		 * @return void
		 */
		public static function reset(): void {
			self::$instance = null;
		}
	}