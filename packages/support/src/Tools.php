<?php
	
	namespace Quellabs\Support;
	
	class Tools {
		
		/**
		 * Get user's IP address
		 * @url https://stackoverflow.com/questions/3003145/how-to-get-the-client-ip-address-in-php
		 * @return string|null
		 */
		public static function getIPAddress(): ?string {
			$sections = [
				'HTTP_CLIENT_IP',
				'HTTP_X_FORWARDED_FOR',
				'HTTP_X_FORWARDED',
				'HTTP_X_CLUSTER_CLIENT_IP',
				'HTTP_FORWARDED_FOR',
				'HTTP_FORWARDED',
				'REMOTE_ADDR'
			];
			
			foreach ($sections as $key) {
				if (array_key_exists($key, $_SERVER)) {
					$ipAddresses = array_map(function($e) { return trim($e); }, explode(',', $_SERVER[$key]));
					
					foreach ($ipAddresses as $ip) {
						if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
							return $ip;
						}
					}
				}
			}
			
			return null;
		}
		
		/**
		 * Detects the version of a UUID
		 * @param string $uuid The UUID to check
		 * @return int|null The UUID version (1-8), or null if invalid UUID
		 */
		public static function getUUIDVersion(string $uuid): ?int {
			// Remove curly braces if present
			$uuid = trim($uuid, "{}");
			
			// Basic validation
			if (strlen($uuid) !== 36) {
				return null;
			}
			
			// Extract parts
			$parts = explode('-', $uuid);
			
			// Validate parts
			if (
				count($parts) !== 5 ||
				strlen($parts[0]) !== 8 || !ctype_xdigit($parts[0]) ||
				strlen($parts[1]) !== 4 || !ctype_xdigit($parts[1]) ||
				strlen($parts[2]) !== 4 || !ctype_xdigit($parts[2]) ||
				strlen($parts[3]) !== 4 || !ctype_xdigit($parts[3]) ||
				strlen($parts[4]) !== 12 || !ctype_xdigit($parts[4])
			) {
				return null;
			}
			
			// Extract version from the first character of the third section
			$version = (int)$parts[2][0];
			
			// Valid UUID versions are 1-8 (though 2 is rarely used, 8 is experimental)
			if ($version < 1 || $version > 8) {
				return null;
			}
			
			// Return the version
			return $version;
		}
		
		/**
		 * Returns a new UUID v4 (random globally unique identifier)
		 * Note: For database primary keys, consider using createUUIDv7() instead
		 * for better index performance and natural time-ordering.
		 * @return string UUID v4 in the format xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
		 * @throws \Exception If random_bytes() fails to generate random data
		 */
		public static function createUUIDv4(): string {
			// Generate 16 random bytes (PHP 7.0+)
			$data = random_bytes(16);
			
			// Set version to 0100 (UUID v4)
			$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
			
			// Set variant to 10xx (RFC 4122)
			$data[8] = chr(ord($data[8]) & 0x3f | 0x80);
			
			// Return the formatted UUID
			return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
		}
		
		/**
		 * Returns a new UUID v7 (time-ordered, optimal for database primary keys)
		 *
		 * UUID v7 uses a timestamp prefix for natural sorting and reduced index fragmentation.
		 * This makes it significantly better than v4 for use as database primary keys:
		 * - Monotonically increasing (mostly)
		 * - Reduced B-tree fragmentation
		 * - Better cache locality
		 * - Lower write amplification
		 *
		 * @return string UUID v7 in the format xxxxxxxx-xxxx-7xxx-yxxx-xxxxxxxxxxxx
		 * @throws \Exception If random_bytes() fails to generate random data
		 */
		public static function createUUIDv7(): string {
			// Get current time in milliseconds since Unix epoch
			$timestamp = (int)(microtime(true) * 1000);
			
			// Generate random bytes for the random portion (PHP 7.0+)
			$randomBytes = random_bytes(10);
			
			// Build the UUID v7:
			// - 48 bits: timestamp (milliseconds since epoch)
			// - 4 bits: version (0111 = 7)
			// - 12 bits: random
			// - 2 bits: variant (10)
			// - 62 bits: random
			$timestampHex = str_pad(dechex($timestamp), 12, '0', STR_PAD_LEFT);
			
			// Extract timestamp bytes
			$timeHi = substr($timestampHex, 0, 8);
			$timeLow = substr($timestampHex, 8, 4);
			
			// Random data
			$randomHex = bin2hex($randomBytes);
			
			// Version: 4 bits of version (7) + 12 bits random
			$versionAndRand = dechex(0x7000 | (hexdec(substr($randomHex, 0, 3)) & 0x0fff));
			$versionAndRand = str_pad($versionAndRand, 4, '0', STR_PAD_LEFT);
			
			// Variant: 2 bits (10) + 14 bits random
			$variantAndRand = dechex(0x8000 | (hexdec(substr($randomHex, 3, 4)) & 0x3fff));
			$variantAndRand = str_pad($variantAndRand, 4, '0', STR_PAD_LEFT);
			
			// Remaining random bits
			$randLow = substr($randomHex, 7, 12);
			
			// Return the formatted UUID
			return sprintf(
				'%s-%s-%s-%s-%s',
				$timeHi,
				$timeLow,
				$versionAndRand,
				$variantAndRand,
				$randLow
			);
		}
		
		/**
		 * Alias for createUUIDv4() for backward compatibility
		 * @return string
		 * @throws \Exception
		 * @deprecated Use createUUIDv4() or createUUIDv7() instead
		 */
		public static function createGUID(): string {
			return self::createUUIDv4();
		}

		/**
		 * Validates a UUID and optionally checks for a specific version
		 * Automatically detects the version and delegates to the appropriate validator
		 * Only supports UUID v4 and v7 - other versions will throw an exception
		 * @param string $uuid The UUID to validate
		 * @return bool True if valid UUID
		 * @throws \InvalidArgumentException If UUID version is not 4 or 7
		 */
		public static function validateUUID(string $uuid): bool {
			// Detect version
			$detectedVersion = self::getUUIDVersion($uuid);
			
			// If we can't detect a valid version, it's invalid
			if ($detectedVersion === null) {
				return false;
			}
			
			// Only support v4 and v7
			return match ($detectedVersion) {
				4 => self::validateUUIDv4($uuid),
				7 => self::validateUUIDv7($uuid),
				default => throw new \InvalidArgumentException(
					"UUID version {$detectedVersion} is not supported. Only v4 and v7 are supported."
				),
			};
		}
		
		/**
		 * Alias for validateUUID() for backward compatibility
		 * @param string $uuid The UUID to validate
		 * @return bool True if valid UUID
		 * @deprecated Use validateUUID($uuid) instead of validateGUID($uuid)
		 */
		public static function validateGUID(string $uuid): bool {
			return self::validateUUID($uuid);
		}
		
		/**
		 * Validates specifically a UUID v4
		 * @param string $uuid The UUID to validate
		 * @return bool True if valid UUID v4
		 */
		public static function validateUUIDv4(string $uuid): bool {
			// Remove curly braces if present
			$uuid = trim($uuid, "{}");
			
			// Check that the string has the correct length
			if (strlen($uuid) !== 36) {
				return false;
			}
			
			// Split the string into parts
			$parts = explode('-', $uuid);
			
			// Check that the string has the correct format
			if (count($parts) !== 5) {
				return false;
			}
			
			// Check that each part has the correct length and is hexadecimal
			if (
				strlen($parts[0]) !== 8 || !ctype_xdigit($parts[0]) ||
				strlen($parts[1]) !== 4 || !ctype_xdigit($parts[1]) ||
				strlen($parts[2]) !== 4 || !ctype_xdigit($parts[2]) ||
				strlen($parts[3]) !== 4 || !ctype_xdigit($parts[3]) ||
				strlen($parts[4]) !== 12 || !ctype_xdigit($parts[4])
			) {
				return false;
			}
			
			// Check version bits (first character of third section should be '4')
			if ($parts[2][0] !== '4') {
				return false;
			}
			
			// Check variant bits (first character of fourth section should be 8, 9, a, or b)
			if (!in_array(strtolower($parts[3][0]), ['8', '9', 'a', 'b'], true)) {
				return false;
			}
			
			return true;
		}
		
		/**
		 * Validates specifically a UUID v7
		 * @param string $uuid The UUID to validate
		 * @return bool True if valid UUID v7
		 */
		public static function validateUUIDv7(string $uuid): bool {
			// Remove curly braces if present
			$uuid = trim($uuid, "{}");
			
			// Check that the string has the correct length
			if (strlen($uuid) !== 36) {
				return false;
			}
			
			// Split the string into parts
			$parts = explode('-', $uuid);
			
			// Check that the string has the correct format
			if (count($parts) !== 5) {
				return false;
			}
			
			// Check that each part has the correct length and is hexadecimal
			if (
				strlen($parts[0]) !== 8 || !ctype_xdigit($parts[0]) ||
				strlen($parts[1]) !== 4 || !ctype_xdigit($parts[1]) ||
				strlen($parts[2]) !== 4 || !ctype_xdigit($parts[2]) ||
				strlen($parts[3]) !== 4 || !ctype_xdigit($parts[3]) ||
				strlen($parts[4]) !== 12 || !ctype_xdigit($parts[4])
			) {
				return false;
			}
			
			// Check version bits (first character of third section should be '7')
			if ($parts[2][0] !== '7') {
				return false;
			}
			
			// Check variant bits (first character of fourth section should be 8, 9, a, or b)
			if (!in_array(strtolower($parts[3][0]), ['8', '9', 'a', 'b'], true)) {
				return false;
			}
			
			// Additional v7 validation: timestamp should be reasonable
			// Extract timestamp and check it's not in the future or too far in the past
			$uuidNoHyphens = str_replace('-', '', $uuid);
			$timestampHex = substr($uuidNoHyphens, 0, 12);
			$timestamp = hexdec($timestampHex);
			
			// Timestamp should be between 2020-01-01 and ~100 years in the future
			$minTimestamp = 1577836800000; // 2020-01-01 00:00:00 UTC in milliseconds
			$maxTimestamp = (time() + (100 * 365 * 24 * 60 * 60)) * 1000; // ~100 years from now
			
			if ($timestamp < $minTimestamp || $timestamp > $maxTimestamp) {
				return false;
			}
			
			return true;
		}
		
		/**
		 * Extracts the timestamp from a UUID v7
		 * @param string $uuid The UUID v7
		 * @return int|null Unix timestamp in milliseconds, or null if invalid
		 */
		public static function getUUIDv7Timestamp(string $uuid): ?int {
			// Validate that the UUID is v7
			if (!self::validateUUIDv7($uuid)) {
				return null;
			}

			// Extract elements
			$uuid = str_replace('-', '', $uuid);
			
			// Extract first 12 hex characters (48 bits = timestamp)
			$timestampHex = substr($uuid, 0, 12);
			
			// Convert hex to dec and return timestamp
			return hexdec($timestampHex);
		}
		
		/**
		 * Converts a UUID v7 timestamp to a DateTime object
		 * @param string $uuid The UUID v7
		 * @return \DateTime|null DateTime object, or null if invalid
		 */
		public static function getUUIDv7DateTime(string $uuid): ?\DateTime {
			// Fetch and validate timestamp
			$timestamp = self::getUUIDv7Timestamp($uuid);
			
			if ($timestamp === null) {
				return null;
			}
			
			// Convert milliseconds to seconds with microseconds
			$seconds = (int)($timestamp / 1000);
			$microseconds = ($timestamp % 1000) * 1000;
			
			// Convert seconds to datetime
			$dateTime = new \DateTime();
			$dateTime->setTimestamp($seconds);
			$dateTime->modify("+{$microseconds} microseconds");
			return $dateTime;
		}
		
		/**
		 * Gets the length of the longest value in an enum.
		 * For backed enums, measures the value length.
		 * For pure enums, measures the name length.
		 * @param string $enumClass The fully qualified enum class name
		 * @return int The length of the longest enum value
		 * @throws \InvalidArgumentException If the class is not an enum or doesn't exist
		 */
		public static function getMaxEnumValueLength(string $enumClass): int {
			// Verify the class exists first
			if (!class_exists($enumClass) && !enum_exists($enumClass)) {
				throw new \InvalidArgumentException("Class {$enumClass} does not exist");
			}
			
			// Verify the provided class is actually an enum
			if (!enum_exists($enumClass)) {
				throw new \InvalidArgumentException("{$enumClass} is not a valid enum");
			}
			
			// Use reflection to get enum cases
			$reflection = new \ReflectionEnum($enumClass);
			$cases = $reflection->getCases();
			
			// Handle empty enums (edge case)
			if (empty($cases)) {
				return 0;
			}
			
			// Track the maximum length found
			$maxLength = 0;
			
			// Iterate through each enum case
			foreach ($cases as $case) {
				// Get the actual enum case value
				$enumCase = $case->getValue();
				
				// For backed enums, use the value; for pure enums, use the name
				// Cast to string to handle int-backed enums correctly
				if ($enumCase instanceof \BackedEnum) {
					$value = (string)$enumCase->value;
				} else {
					$value = $enumCase->name;
				}
				
				// Update max length if current value is longer
				$length = strlen($value);
				
				if ($length > $maxLength) {
					$maxLength = $length;
				}
			}
			
			return $maxLength;
		}
	}