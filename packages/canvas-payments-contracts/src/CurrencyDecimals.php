<?php

	namespace Quellabs\Payments\Contracts;

	/**
	 * Converts between minor units (the ISO 4217 smallest unit used by every contract amount) and major units.
	 * @url https://www.iso.org/iso-4217-currency-codes.html
	 */
	final class CurrencyDecimals {

		/** ISO 4217 currencies with 0 minor-unit digits */
		private const ZERO_DECIMAL_CURRENCIES = [
			'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG',
			'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
		];

		/** ISO 4217 currencies with 3 minor-unit digits */
		private const THREE_DECIMAL_CURRENCIES = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

		/**
		 * Returns the ISO 4217 minor-unit digits for a currency; 2 for any currency not listed as 0 or 3.
		 * @param string $currency ISO 4217 currency code
		 * @return int
		 */
		public static function decimals(string $currency): int {
			$code = strtoupper($currency);

			if (in_array($code, self::ZERO_DECIMAL_CURRENCIES, true)) {
				return 0;
			}

			if (in_array($code, self::THREE_DECIMAL_CURRENCIES, true)) {
				return 3;
			}

			return 2;
		}

		/**
		 * Converts a major-unit amount (e.g. 10.5 EUR) to minor units (1050).
		 * @param float $value
		 * @param string $currency ISO 4217 currency code
		 * @return int
		 */
		public static function toMinorUnits(float $value, string $currency): int {
			return (int)round($value * (10 ** self::decimals($currency)));
		}

		/**
		 * Converts a minor-unit amount (e.g. 1050 EUR) to major units (10.5).
		 * @param int $amount
		 * @param string $currency ISO 4217 currency code
		 * @return float
		 */
		public static function toMajorUnits(int $amount, string $currency): float {
			return $amount / (10 ** self::decimals($currency));
		}

		/**
		 * Formats a minor-unit amount as a major-unit string with $decimals places (default: the ISO digits).
		 * @param int $amount
		 * @param string $currency ISO 4217 currency code
		 * @param int|null $decimals Decimal places the receiving API expects, or null for the ISO digits
		 * @return string E.g. 1050 EUR → "10.50", 1000 JPY → "1000"
		 * @throws \InvalidArgumentException When $amount has more precision than $decimals can express
		 */
		public static function formatMajorUnits(int $amount, string $currency, ?int $decimals = null): string {
			$isoDecimals = self::decimals($currency);
			$decimals    = $decimals ?? $isoDecimals;

			if ($decimals < $isoDecimals && $amount % (10 ** ($isoDecimals - $decimals)) !== 0) {
				throw new \InvalidArgumentException("Amount {$amount} {$currency} cannot be expressed with {$decimals} decimal places.");
			}

			return number_format($amount / (10 ** $isoDecimals), $decimals, '.', '');
		}
	}
