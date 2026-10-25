<?php

	declare(strict_types=1);

	namespace Quellabs\ObjectQuel\Tests\Unit\DatabaseAdapter;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;

	/**
	 * Shared abstract type mapping rules.
	 */
	class TypeMapperTest extends TestCase {

		/**
		 * An unknown type must not silently become a VARCHAR column.
		 * @return void
		 */
		public function testUnknownColumnTypeIsRejectedBeforeSqlRendering(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage("Unknown abstract column type 'missing_type'");

			TypeMapper::sqlColumnType(
				['type' => 'missing_type', 'limit' => null, 'unsigned' => false, 'precision' => null, 'scale' => null, 'values' => null],
				'mysql',
				true,
				true,
				static fn(string $value): string => "'{$value}'"
			);
		}
	}
