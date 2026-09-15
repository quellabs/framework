<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Annotations;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;

	/**
	 * hasDefault() used to be `!empty($this->parameters["default"])`, which
	 * treats a declared default of 0, '0', '', or false as "no default" —
	 * wrong, since those are valid column defaults distinct from never
	 * declaring one at all. Fixed to `!== null`, matching getDefault()'s own
	 * `?? null` fallback and QuelToSQLAppend::assertRequiredColumnsSupplied(),
	 * which already used the correct `!== null` check on the raw metadata.
	 */
	class ColumnTest extends TestCase {

		private function column(mixed $default): Column {
			return new Column([
				'name' => 'col',
				'type' => 'integer',
				'default' => $default,
			]);
		}

		public function testHasDefaultIsTrueForFalsyDefaults(): void {
			self::assertTrue($this->column(0)->hasDefault());
			self::assertTrue($this->column('0')->hasDefault());
			self::assertTrue($this->column('')->hasDefault());
			self::assertTrue($this->column(false)->hasDefault());
		}

		public function testHasDefaultIsFalseWhenNoDefaultIsDeclared(): void {
			self::assertFalse($this->column(null)->hasDefault());
			self::assertFalse((new Column(['name' => 'col', 'type' => 'integer']))->hasDefault());
		}

		public function testGetDefaultReturnsTheDeclaredValueUnchanged(): void {
			self::assertSame(0, $this->column(0)->getDefault());
			self::assertSame('', $this->column('')->getDefault());
			self::assertFalse($this->column(false)->getDefault());
		}
	}
