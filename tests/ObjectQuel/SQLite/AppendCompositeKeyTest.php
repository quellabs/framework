<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Regression coverage for AppendExecutor::fillGeneratedPrimaryKeys()
	 * against a composite primary key: every key component is checked and
	 * generated independently (not just the first), a component with no
	 * generation strategy and no supplied value is rejected before any SQL
	 * runs, a 'sequence' component is rejected for a multi-row append
	 * regardless of its position among the key's components, and a
	 * single-row append's generated ID is a complete property-keyed map
	 * rather than one scalar standing in for the whole key.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] — see
	 * RelationshipCascadeForeignKeyTest for why a second EntityManager can't
	 * be constructed in this process.
	 */
	class AppendCompositeKeyTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute('DELETE FROM cki_orders');
			$adapter->execute('DELETE FROM cki_generated_key_orders');
			$adapter->execute('DELETE FROM cki_sequence_orders');
			self::em()->getUnitOfWork()->clear();
		}

		public function testAppendGeneratesOnlyTheMissingCompositeKeyComponentAndReturnsACompleteIdMap(): void {
			$result = self::em()->executeQuery('
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkiGeneratedKeyOrderEntity
				append to o (tenantId = :tenantId, amount = :amount)
			', ['tenantId' => 7, 'amount' => 100]);

			$this->assertSame(1, $result->getAffectedRows());

			$generatedId = $result->getGeneratedId();
			$this->assertIsArray($generatedId);
			$this->assertSame(['tenantId', 'token'], array_keys($generatedId));
			$this->assertSame(7, $generatedId['tenantId']);
			$this->assertIsString($generatedId['token']);
			$this->assertNotSame('', $generatedId['token']);

			$row = self::em()->getAll('
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkiGeneratedKeyOrderEntity
				retrieve (o.tenantId, o.token, o.amount)
				where o.tenantId = :tenantId and o.token = :token
			', ['tenantId' => 7, 'token' => $generatedId['token']]);

			$this->assertCount(1, $row);
			$this->assertSame(100, $row[0]['o.amount']);
		}

		public function testAppendReportsNoGeneratedIdWhenEveryCompositeKeyComponentIsSupplied(): void {
			$result = self::em()->executeQuery('
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkiOrderEntity
				append to o (tenantId = :tenantId, number = :number, amount = :amount)
			', ['tenantId' => 1, 'number' => 'A-1', 'amount' => 50]);

			$this->assertSame(1, $result->getAffectedRows());
			$this->assertNull($result->getGeneratedId());
		}

		public function testAppendRejectsACompositeKeyComponentWithNoStrategyAndNoSuppliedValue(): void {
			$this->expectException(QuelException::class);

			// 'number' has no @PrimaryKeyStrategy (composite default: assigned)
			// and isn't supplied here — must be rejected before any SQL runs,
			// not silently inserted as missing/NULL.
			self::em()->executeQuery('
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkiOrderEntity
				append to o (tenantId = :tenantId, amount = :amount)
			', ['tenantId' => 1, 'amount' => 50]);
		}

		public function testAppendRejectsASequenceStrategyComponentForAMultiRowAppendRegardlessOfItsPosition(): void {
			$this->expectException(QuelException::class);

			// 'seq' is the *second* key component and carries the 'sequence'
			// strategy — rejected for a multi-row append the same way a
			// first-component 'sequence' strategy already was.
			self::em()->executeQuery('
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkiSequenceOrderEntity
				append to o
					(groupId = 1),
					(groupId = 1)
			');
		}
	}
