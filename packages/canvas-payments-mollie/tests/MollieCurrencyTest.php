<?php

	namespace Quellabs\Payments\Mollie\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Payments\Mollie\MollieCurrency;

	/**
	 * Confirms minor-unit conversion uses Mollie's per-currency decimal places.
	 */
	class MollieCurrencyTest extends TestCase {

		/**
		 * ISK and JPY use 0 decimals, case-insensitively; other currencies use 2.
		 * @return void
		 */
		public function testDecimals(): void {
			$this->assertSame(0, MollieCurrency::decimals('JPY'));
			$this->assertSame(0, MollieCurrency::decimals('isk'));
			$this->assertSame(2, MollieCurrency::decimals('EUR'));
			$this->assertSame(2, MollieCurrency::decimals('HUF'));
		}

		/**
		 * toMinorUnits() scales by the currency's decimals and rounds float noise away.
		 * @return void
		 */
		public function testToMinorUnits(): void {
			$this->assertSame(1050, MollieCurrency::toMinorUnits(10.50, 'EUR'));
			$this->assertSame(1975, MollieCurrency::toMinorUnits(19.75, 'EUR'));
			$this->assertSame(1000, MollieCurrency::toMinorUnits(1000.0, 'JPY'));
			$this->assertSame(500, MollieCurrency::toMinorUnits(500.0, 'ISK'));
		}

		/**
		 * formatMinorUnits() writes the currency's decimals, with none for zero-decimal currencies.
		 * @return void
		 */
		public function testFormatMinorUnits(): void {
			$this->assertSame('10.50', MollieCurrency::formatMinorUnits(1050, 'EUR'));
			$this->assertSame('0.05', MollieCurrency::formatMinorUnits(5, 'EUR'));
			$this->assertSame('1000', MollieCurrency::formatMinorUnits(1000, 'JPY'));
			$this->assertSame('1234567.89', MollieCurrency::formatMinorUnits(123456789, 'USD'));
		}
	}
