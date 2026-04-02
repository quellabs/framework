<?php
	
	namespace Quellabs\Shipments\DPD;
	
	use Quellabs\Support\ComposerUtils;
	
	/**
	 * File-based cache for DPD parcel label PDFs.
	 *
	 * DPD does not provide a label re-fetch endpoint — the label PDF is returned
	 * only in the creation response. This class persists that label to disk so it
	 * can be retrieved in any subsequent request or process.
	 *
	 * Each label is stored as a file named after its parcel label number (sanitised
	 * to alphanumeric only to prevent path traversal) with a .label extension.
	 *
	 * TTL:
	 *   When ttlDays > 0, files older than ttlDays days are considered expired.
	 *   Stale files are purged opportunistically on every read and write.
	 *   When ttlDays === 0, files are kept indefinitely and no purge is performed.
	 *
	 * Failure policy:
	 *   All filesystem errors (failed mkdir, failed write, unreadable directory)
	 *   are silently swallowed. A cache failure must never abort shipment creation
	 *   or label retrieval — callers check the return value for null.
	 */
	class LabelFileCache {
		
		/** @var string Absolute path to the cache directory */
		private string $cacheDir;
		
		/** @var int Days to retain files; 0 = keep indefinitely */
		private int $ttlDays;
		
		/**
		 * @param string $cachePath  Absolute or project-root-relative path to the cache directory
		 * @param int    $ttlDays    Days to retain label files; 0 disables expiry
		 */
		public function __construct(string $cachePath, int $ttlDays) {
			$this->ttlDays  = max(0, $ttlDays);

			if (str_starts_with($cachePath, DIRECTORY_SEPARATOR)) {
				$this->cacheDir = $cachePath;
			} else {
				$this->cacheDir = ComposerUtils::getProjectRoot() . DIRECTORY_SEPARATOR . ltrim($cachePath, DIRECTORY_SEPARATOR);
			}
		}
		
		/**
		 * Writes a base64-encoded label to the cache.
		 * Creates the cache directory if it does not exist.
		 * Silently does nothing on any filesystem failure.
		 * @param string $parcelLabelNumber
		 * @param string $base64Content
		 * @return void
		 */
		public function write(string $parcelLabelNumber, string $base64Content): void {
			try {
				if (!is_dir($this->cacheDir)) {
					mkdir($this->cacheDir, 0755, true);
				}
				
				file_put_contents($this->filePath($parcelLabelNumber), $base64Content, LOCK_EX);
				
				if ($this->ttlDays > 0) {
					$this->purge();
				}
			} catch (\Throwable) {
				// Silently skip — a failed cache write must not prevent shipment creation
			}
		}
		
		/**
		 * Reads a base64-encoded label from the cache.
		 * Returns null if the file does not exist or has expired.
		 * Purges stale files opportunistically when TTL is active.
		 * @param string $parcelLabelNumber
		 * @return string|null
		 */
		public function read(string $parcelLabelNumber): ?string {
			if ($this->ttlDays > 0) {
				$this->purge();
			}
			
			$path = $this->filePath($parcelLabelNumber);
			
			if (!is_file($path)) {
				return null;
			}
			
			if ($this->isExpired($path)) {
				@unlink($path);
				return null;
			}
			
			$content = file_get_contents($path);
			return $content !== false ? $content : null;
		}
		
		/**
		 * Returns the absolute file path for a given parcel label number.
		 * The label number is sanitised to alphanumeric only to prevent path traversal.
		 * @param string $parcelLabelNumber
		 * @return string
		 */
		private function filePath(string $parcelLabelNumber): string {
			$safe = preg_replace('/[^a-zA-Z0-9]/', '', $parcelLabelNumber);
			return $this->cacheDir . DIRECTORY_SEPARATOR . $safe . '.label';
		}
		
		/**
		 * Returns true when the file at $path is older than the configured TTL.
		 * Always returns false when ttlDays is 0 (indefinite retention).
		 * @param string $path
		 * @return bool
		 */
		private function isExpired(string $path): bool {
			if ($this->ttlDays === 0) {
				return false;
			}
			
			return (time() - filemtime($path)) > ($this->ttlDays * 86400);
		}
		
		/**
		 * Deletes all .label files in the cache directory that have exceeded the TTL.
		 * Only called when ttlDays > 0.
		 * Silently skips if the directory does not exist or is unreadable.
		 * @return void
		 */
		private function purge(): void {
			if (!is_dir($this->cacheDir)) {
				return;
			}
			
			try {
				foreach (new \DirectoryIterator($this->cacheDir) as $file) {
					if ($file->isDot() || !$file->isFile() || $file->getExtension() !== 'label') {
						continue;
					}
					
					if ($this->isExpired($file->getPathname())) {
						@unlink($file->getPathname());
					}
				}
			} catch (\Throwable) {
				// Silently skip — cleanup failures must not affect cache reads or writes
			}
		}
	}