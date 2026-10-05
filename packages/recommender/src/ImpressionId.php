<?php
	
	namespace Quellabs\Recommender;
	
	use Random\RandomException;
	
	/** Opaque random 16-byte impression token exposed as lowercase hex. */
	readonly class ImpressionId {
		
		/** @var string 32 lowercase hexadecimal characters */
		public string $hex;
		
		/**
		 * Build an impression token from its hex form.
		 * @param string $hex 32 lowercase hexadecimal characters
		 * @throws \InvalidArgumentException When the value is not 32 lowercase hexadecimal characters
		 */
		public function __construct(string $hex) {
			if (preg_match('/^[0-9a-f]{32}$/D', $hex) !== 1) {
				throw new \InvalidArgumentException("Impression ID must be 32 lowercase hexadecimal characters, got '{$hex}'.");
			}
			
			$this->hex = $hex;
		}
		
		/**
		 * Generate a fresh random impression token.
		 * @return self Fresh random impression token
		 * @throws RandomException
		 */
		public static function generate(): self {
			return new self(bin2hex(random_bytes(16)));
		}
		
		/**
		 * Return the token as the raw bytes stored in the database.
		 * @return string Raw database key
		 * @throws \LogicException When the validated hex value cannot be decoded
		 */
		public function binary(): string {
			$binary = hex2bin($this->hex);
			
			if ($binary === false) {
				throw new \LogicException("Validated impression ID '{$this->hex}' could not be decoded.");
			}
			
			return $binary;
		}
		
		/**
		 * Return the display token.
		 * @return string Display token
		 */
		public function __toString(): string {
			return $this->hex;
		}
	}
