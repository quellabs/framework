<?php
	
	namespace Quellabs\Canvas\Loom\Validation\Rules;
	
	/**
	 * Fails if the value is not a valid postal code for the given country.
	 * Defaults to NL. Pass an ISO 3166-1 alpha-2 country code to validate
	 * a different country's format.
	 * Empty values pass — combine with NotBlank if the field is required.
	 */
	class Zipcode extends RuleBase {
		
		/** @var string ISO 3166-1 alpha-2 country code */
		private string $countryIso2;
		
		/**
		 * Format map: # = digit, @ = letter, * = alphanumeric.
		 * @var array<string, string[]>
		 */
		private array $formats = [
			'AC' => [], 'AD' => ['AD###', '#####'], 'AE' => [], 'AF' => ['####'],
			'AG' => [], 'AI' => ['AI-2640'], 'AL' => ['####'], 'AM' => ['####'],
			'AN' => [], 'AO' => [], 'AQ' => ['BIQQ 1ZZ'], 'AR' => ['####', '@####@@@'],
			'AS' => ['#####', '#####-####'], 'AT' => ['####'], 'AU' => ['####'],
			'AW' => [], 'AX' => ['#####', 'AX-#####'], 'AZ' => ['AZ ####'],
			'BA' => ['#####'], 'BB' => ['BB#####'], 'BD' => ['####'], 'BE' => ['####'],
			'BF' => [], 'BG' => ['####'], 'BH' => ['###', '####'], 'BI' => [],
			'BJ' => [], 'BL' => ['#####'], 'BM' => ['@@ ##', '@@ @@'], 'BN' => ['@@####'],
			'BO' => [], 'BR' => ['#####-###', '#####'], 'BS' => [], 'BT' => ['#####'],
			'BY' => ['######'], 'BZ' => [], 'CA' => ['@#@ #@#'], 'CC' => ['####'],
			'CH' => ['####'], 'CL' => ['#######', '###-####'], 'CN' => ['######'],
			'CO' => ['######'], 'CR' => ['#####', '#####-####'], 'CU' => ['#####'],
			'CV' => ['####'], 'CX' => ['####'], 'CY' => ['####'], 'CZ' => ['### ##'],
			'DE' => ['#####'], 'DK' => ['####'], 'DO' => ['#####'], 'DZ' => ['#####'],
			'EC' => ['######'], 'EE' => ['#####'], 'EG' => ['#####'], 'ES' => ['#####'],
			'ET' => ['####'], 'FI' => ['#####'], 'FM' => ['#####', '#####-####'],
			'FO' => ['###'], 'FR' => ['#####'], 'GB' => ['@@## #@@', '@#@ #@@', '@@# #@@', '@@#@ #@@', '@## #@@', '@# #@@'],
			'GE' => ['####'], 'GF' => ['973##'], 'GL' => ['####'], 'GN' => ['###'],
			'GP' => ['971##'], 'GR' => ['### ##'], 'GT' => ['#####'], 'GU' => ['#####', '#####-####'],
			'GW' => ['####'], 'HN' => ['@@####', '#####'], 'HR' => ['#####'],
			'HT' => ['####'], 'HU' => ['####'], 'ID' => ['#####'], 'IL' => ['#######'],
			'IN' => ['######', '### ###'], 'IQ' => ['#####'], 'IR' => ['##########', '#####-#####'],
			'IS' => ['###'], 'IT' => ['#####'], 'JM' => ['##'], 'JO' => ['#####'],
			'JP' => ['###-####', '###'], 'KE' => ['#####'], 'KG' => ['######'],
			'KH' => ['#####'], 'KR' => ['###-###', '#####'], 'KW' => ['#####'],
			'KY' => ['KY#-####'], 'KZ' => ['######'], 'LA' => ['#####'],
			'LB' => ['#####', '#### ####'], 'LI' => ['####'], 'LK' => ['#####'],
			'LR' => ['####'], 'LS' => ['###'], 'LT' => ['LT-#####', '#####'],
			'LU' => ['####'], 'LV' => ['LV-####'], 'MA' => ['#####'], 'MC' => ['980##'],
			'MD' => ['MD####', 'MD-####'], 'ME' => ['#####'], 'MG' => ['###'],
			'MH' => ['#####', '#####-####'], 'MK' => ['####'], 'MM' => ['#####'],
			'MN' => ['#####'], 'MP' => ['#####', '#####-####'], 'MQ' => ['972##'],
			'MT' => ['@@@ ####'], 'MU' => ['#####'], 'MV' => ['#####'], 'MX' => ['#####'],
			'MY' => ['#####'], 'MZ' => ['####'], 'NC' => ['988##'], 'NE' => ['####'],
			'NF' => ['####'], 'NG' => ['######'], 'NI' => ['#####'], 'NL' => ['####@@', '#### @@'],
			'NO' => ['####'], 'NP' => ['#####'], 'NZ' => ['####'], 'OM' => ['###'],
			'PA' => ['####'], 'PE' => ['#####', 'PE #####'], 'PF' => ['987##'],
			'PG' => ['###'], 'PH' => ['####'], 'PK' => ['#####'], 'PL' => ['##-###'],
			'PR' => ['#####', '#####-####'], 'PS' => ['###'], 'PT' => ['####-###'],
			'PW' => ['#####', '#####-####'], 'PY' => ['####'], 'RE' => ['974##'],
			'RO' => ['######'], 'RS' => ['#####'], 'RU' => ['######'],
			'SA' => ['#####', '#####-####'], 'SD' => ['#####'], 'SE' => ['### ##'],
			'SG' => ['######'], 'SI' => ['####', 'SI-####'], 'SJ' => ['####'],
			'SK' => ['### ##'], 'SM' => ['4789#'], 'SN' => ['#####'],
			'SO' => ['@@ #####'], 'SS' => ['#####'], 'SV' => ['####'],
			'SZ' => ['@###'], 'TH' => ['#####'], 'TJ' => ['######'], 'TM' => ['######'],
			'TN' => ['####'], 'TR' => ['#####'], 'TT' => ['######'], 'TW' => ['###', '###-##'],
			'TZ' => ['#####'], 'UA' => ['#####'], 'US' => ['#####', '#####-####'],
			'UY' => ['#####'], 'UZ' => ['######'], 'VA' => ['00120'],
			'VC' => ['VC####'], 'VE' => ['####', '####-@'], 'VG' => ['VG####'],
			'VI' => ['#####', '#####-####'], 'VN' => ['######'], 'WS' => ['WS####'],
			'ZA' => ['####'], 'ZM' => ['#####'], 'XK' => ['#####'],
		];
		
		/**
		 * @param string $countryIso2 ISO 3166-1 alpha-2 country code (default 'NL')
		 * @param string|null $message Optional custom error message
		 */
		public function __construct(string $countryIso2 = 'NL', ?string $message = null) {
			parent::__construct($message);
			$this->countryIso2 = strtoupper($countryIso2);
		}
		
		/**
		 * Converts a format string to a regex pattern.
		 * # = digit, @ = letter, * = alphanumeric. Spaces are optional.
		 */
		private function formatToPattern(string $format): string {
			$pattern = str_replace(['#', '@', '*', ' '], ['\d', '[a-zA-Z]', '[a-zA-Z0-9]', ' ?'], $format);
			return '/^' . $pattern . '$/';
		}
		
		/**
		 * @inheritDoc
		 */
		public function validate(mixed $value): bool {
			// Empty value is allowed
			if ($value === null || $value === '') {
				return true;
			}
			
			// Fetch zipcode patterns
			$formats = $this->formats[$this->countryIso2] ?? null;
			
			// Unknown country — pass validation
			if ($formats === null) {
				return true;
			}
			
			// Country with no defined format — pass validation
			if (empty($formats)) {
				return true;
			}
			
			// Remove whitespace
			$trimmed = trim((string)$value);
			
			// Check patterns
			foreach ($formats as $format) {
				if (preg_match($this->formatToPattern($format), $trimmed)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * @inheritDoc
		 */
		public function getError(): string {
			return $this->message ?? 'This value is not a valid postal code.';
		}
	}