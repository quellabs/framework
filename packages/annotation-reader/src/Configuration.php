<?php
	
	namespace Quellabs\AnnotationReader;
	
	/**
	 * Configuration class for AnnotationReader
	 */
	class Configuration {
		
		/**
		 * @var bool True if cache should be used, false if not
		 */
		private bool $useAnnotationCache = false;
		
		/**
		 * @var string Annotation cache directory
		 */
		private string $annotationCachePath = '';
		
		/**
		 * @var bool When true, cache validity is checked against source file mtime on every
		 * read. When false, the cache is trusted unconditionally once it exists.
		 */
		private bool $debugMode = false;
		
		/**
		 * Returns true if the annotationreader should use cache, false if not
		 * @return bool
		 */
		public function useAnnotationCache(): bool {
			return $this->useAnnotationCache;
		}
		
		/**
		 * Sets the annotation reader cache option
		 * @param bool $useAnnotationCache
		 * @return void
		 */
		public function setUseAnnotationCache(bool $useAnnotationCache): void {
			$this->useAnnotationCache = $useAnnotationCache;
		}
		
		/**
		 * Returns the annotation cache directory
		 * @return string
		 */
		public function getAnnotationCachePath(): string {
			return $this->annotationCachePath;
		}
		
		/**
		 * Sets the annotation cache directory
		 * @param string $annotationCacheDir
		 * @return void
		 */
		public function setAnnotationCachePath(string $annotationCacheDir): void {
			$this->annotationCachePath = $annotationCacheDir;
		}
		
		/**
		 * Returns true if debug mode is enabled
		 * @return bool
		 */
		public function isDebugMode(): bool {
			return $this->debugMode;
		}
		
		/**
		 * Sets debug mode. When true, cache files are validated against source file mtime
		 * on every read. When false, existing cache files are trusted unconditionally.
		 * @param bool $debugMode
		 * @return void
		 */
		public function setDebugMode(bool $debugMode): void {
			$this->debugMode = $debugMode;
		}
	}