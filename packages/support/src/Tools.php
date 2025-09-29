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
						if (filter_var($ip, FILTER_VALIDATE_IP,FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
							return $ip;
						}
					}
				}
			}
			
			return null;
		}
		
		/**
		 * Returns a new Global Unique Identifier
		 * @return string
		 */
		public static function createGUID(): string {
			// Use com_create_guid function if present (windows)
			if (function_exists('com_create_guid')) {
				$guid = com_create_guid();
				return (substr($guid, 0, 1) == "{") ? substr($guid, 1, -1) : $guid;
			}
			
			// Use openssl_random_pseudo_bytes function if present
			if (function_exists('openssl_random_pseudo_bytes')) {
				$data = openssl_random_pseudo_bytes(16);
				$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
				$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
				return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
			}
			
			// Fallback to microtime and uniqid
			mt_srand((double)microtime() * 10000);
			$charId = strtolower(md5(uniqid(rand(), true)));
			$hyphen = chr(45);
			
			return substr($charId, 0, 8) . $hyphen .
				substr($charId, 8, 4) . $hyphen .
				substr($charId, 12, 4) . $hyphen .
				substr($charId, 16, 4) . $hyphen .
				substr($charId, 20, 12);
		}
		
		/**
		 * Validates a Global Unique Identifier and returns true if it's valid.
		 * @param string $guid
		 * @return bool
		 */
		public static function validateGUID(string $guid): bool {
			// Check if the input is not empty
			if (empty($guid)) {
				return false;
			}
			
			// Remove curly braces if present
			$guid = trim($guid, "{}");
			
			// Check that the string has the correct length
			if (strlen($guid) !== 36) {
				return false;
			}
			
			// Split the string into parts
			$parts = explode('-', $guid);
			
			// Check that the string has the correct format
			if (count($parts) !== 5) {
				return false;
			}
			
			// Check that each part has the correct length and format
			if (strlen($parts[0]) !== 8 || !ctype_xdigit($parts[0]) ||
				strlen($parts[1]) !== 4 || !ctype_xdigit($parts[1]) ||
				strlen($parts[2]) !== 4 || !ctype_xdigit($parts[2]) ||
				strlen($parts[3]) !== 4 || !ctype_xdigit($parts[3]) ||
				strlen($parts[4]) !== 12 || !ctype_xdigit($parts[4])) {
				return false;
			}
			
			// If all checks pass, the GUID is valid
			return true;
		}
	}