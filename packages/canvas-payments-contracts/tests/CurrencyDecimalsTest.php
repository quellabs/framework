<?php

	namespace Quellabs\Payments\Contracts\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Payments\Contracts\CurrencyDecimals;

	/**
	 * Confirms minor-unit conversion follows ISO 4217 minor-unit digits.
	 */
	class CurrencyDecimalsTest extends TestCase {

		/**
		 * ISO 0- and 3-digit currencies are recognised case-insensitively; all others use 2.
		 * @return void
		 */
		public function testDecimals(): void {
			$this->assertSame(0, CurrencyDecimals::decimals('JPY'));
			$this->assertSame(0, CurrencyDecimals::decimals('isk'));
			$this->assertSame(3, CurrencyDecimals::decimals('KWD'));
			$this->assertSame(2, CurrencyDecimals::decimals('EUR'));
			$this->assertSame(2, CurrencyDecimals::decimals('HUF'));
			$this->assertSame(2, CurrencyDecimals::decimals('TWD'));
		}

		/**
		 * toMinorUnits() scales by the ISO digits and rounds float noise away.
		 * @return void
		 */
		public function testToMinorUnits(): void {
			$this->assertSame(1050, CurrencyDecimals::toMinorUnits(10.50, 'EUR'));
			$this->assertSame(1975, CurrencyDecimals::toMinorUnits(19.75, 'EUR'));
			$this->assertSame(1000, CurrencyDecimals::toMinorUnits(1000.0, 'JPY'));
			$this->assertSame(1500, CurrencyDecimals::toMinorUnits(1.5, 'KWD'));
		}

		/**
		 * toMajorUnits() divides by the ISO digits.
		 * @return void
		 */
		public function testToMajorUnits(): void {
			$this->assertSame(10.5, CurrencyDecimals::toMajorUnits(1050, 'EUR'));
			$this->assertSame(1000.0, CurrencyDecimals::toMajorUnits(1000, 'JPY'));
			$this->assertSame(1.5, CurrencyDecimals::toMajorUnits(1500, 'KWD'));
		}

		/**
		 * formatMajorUnits() defaults to the ISO digits.
		 * @return void
		 */
		public function testFormatMajorUnitsUsesIsoDigitsByDefault(): void {
			$this->assertSame('10.50', CurrencyDecimals::formatMajorUnits(1050, 'EUR'));
			$this->assertSame('0.05', CurrencyDecimals::formatMajorUnits(5, 'EUR'));
			$this->assertSame('1000', CurrencyDecimals::formatMajorUnits(1000, 'JPY'));
			$this->assertSame('1.500', CurrencyDecimals::formatMajorUnits(1500, 'KWD'));
			$this->assertSame('1234567.89', CurrencyDecimals::formatMajorUnits(123456789, 'USD'));
		}

		/**
		 * formatMajorUnits() drops decimals for an API with fewer places when no precision is lost.
		 * @return void
		 */
		public function testFormatMajorUnitsWithFewerDecimals(): void {
			$this->assertSame('1000', CurrencyDecimals::formatMajorUnits(100000, 'HUF', 0));
		}

		/**
		 * formatMajorUnits() throws when fewer decimals would lose precision.
		 * @return void
		 */
		public function testFormatMajorUnitsThrowsWhenPrecisionWouldBeLost(): void {
			$this->expectException(\InvalidArgumentException::class);
			CurrencyDecimals::formatMajorUnits(100050, 'HUF', 0);
		}
	}
