<?php

	namespace Quellabs\Recommender\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Recommender\MemberId;
	use Quellabs\Recommender\ProductId;

	/** Unit tests for the member and product ID value objects. */
	class IdentifierValueObjectsTest extends TestCase {

		/** @return void */
		public function testMemberIdAcceptsUnsigned32BitRange(): void {
			$this->assertSame(4294967295, (new MemberId(4294967295))->value);
		}

		/** @return void */
		public function testMemberIdRejectsNegative(): void {
			$this->expectException(\InvalidArgumentException::class);
			new MemberId(-1);
		}

		/** @return void */
		public function testProductIdRejectsAboveRange(): void {
			$this->expectException(\InvalidArgumentException::class);
			new ProductId(4294967296);
		}
	}
