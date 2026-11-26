<?php

	namespace Quellabs\Payments\Mollie;

	/**
	 * Converts between minor units and Mollie's decimal amount strings, per currency.
	 * @url https://docs.mollie.com/docs/multicurrency
	 */
	final class MollieCurrency {

		/** Mollie currencies with 0 decimal places; every other supported currency has 2 */
		private const ZERO_DECIMAL_CURRENCIES = ['ISK', 'JPY'];

		/**
		 * Returns the number of decimal places Mollie uses for a currency.
		 * @param string $currency ISO 4217 currency code
		 * @return int
		 */
		public static function decimals(string $currency): int {
			return in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;
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
		 * Formats a minor-unit amount as Mollie's amount string (e.g. 1050 EUR → "10.50", 1000 JPY → "1000").
		 * @param int $amount
		 * @param string $currency ISO 4217 currency code
		 * @return string
		 */
		public static function formatMinorUnits(int $amount, string $currency): string {
			$decimals = self::decimals($currency);

			return number_format($amount / (10 ** $decimals), $decimals, '.', '');
		}
	}
