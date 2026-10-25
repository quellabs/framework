<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventBindingNaming;

	/**
	 * Physical binding naming (objectquel-equel-triggers-design.md, "Binding identity
	 * and removal").
	 */
	class EventBindingNamingTest extends TestCase {

		/**
		 * @return void
		 */
		public function testShortNameIsNotTruncated(): void {
			self::assertSame('eq_7dfb4cf67742_audit_trigger', EventBindingNaming::triggerName('users', 'audit_trigger'));
		}

		/**
		 * @return void
		 */
		public function testSameIdentityIsAlwaysTheSameName(): void {
			$first = EventBindingNaming::triggerName('users', 'on_created');
			$second = EventBindingNaming::triggerName('users', 'on_created');
			self::assertSame($first, $second);
		}

		/**
		 * A different alias produces a different name for the same table.
		 * @return void
		 */
		public function testDistinctAliasesProduceDistinctNames(): void {
			$first = EventBindingNaming::triggerName('users', 'f');
			$second = EventBindingNaming::triggerName('users', 'g');
			self::assertNotSame($first, $second);
		}

		/**
		 * A long table name does not consume space reserved for the alias.
		 * @return void
		 */
		public function testLongTableNamePreservesTheAlias(): void {
			$alias = 'a_very_long_alias_as_well';
			$name = EventBindingNaming::triggerName(
				'a_very_long_physical_table_name_that_exceeds_every_engine_limit',
				$alias
			);

			self::assertLessThanOrEqual(60, strlen($name));
			self::assertStringEndsWith('_' . $alias, $name);
		}

		/**
		 * Distinct long table names keep distinct hash prefixes.
		 * @return void
		 */
		public function testLongNamesWithSamePrefixStayDistinct(): void {
			$base = str_repeat('x', 80);
			$first = EventBindingNaming::triggerName($base . '_one', 'f');
			$second = EventBindingNaming::triggerName($base . '_two', 'f');
			self::assertNotSame($first, $second);
		}

		/**
		 * Accepts the longest reversible alias.
		 * @return void
		 */
		public function testAliasAtLengthLimitFits(): void {
			$alias = str_repeat('a', EventBindingNaming::MAX_ALIAS_LENGTH);
			$name = EventBindingNaming::triggerName(str_repeat('t', 60), $alias);
			self::assertSame(60, strlen($name));
			self::assertStringEndsWith($alias, $name);
		}

		/**
		 * Rejects aliases that would have to be shortened.
		 * @return void
		 */
		public function testAliasPastLengthLimitIsRejected(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('44-character limit');
			EventBindingNaming::triggerName('users', str_repeat('a', EventBindingNaming::MAX_ALIAS_LENGTH + 1));
		}

		/**
		 * The PostgreSQL helper name is derived from the trigger name and stays within limits too.
		 * @return void
		 */
		public function testHelperFunctionNameIsDerivedFromTriggerName(): void {
			$trigger = EventBindingNaming::triggerName('users', 'audit_trigger');
			$helper = EventBindingNaming::helperFunctionName($trigger);

			self::assertSame("{$trigger}_fn", $helper);
			self::assertLessThanOrEqual(60, strlen($helper));
		}

		/**
		 * A generated alias is a plain lowercase hex string, with no separators or uppercase
		 * that would need quoting differently across engines.
		 * @return void
		 */
		public function testRandomAliasIsLowercaseHex(): void {
			self::assertMatchesRegularExpression('/^[0-9a-f]+$/', EventBindingNaming::randomAlias());
		}

		/**
		 * Each call generates a distinct alias — it is not derived from any fixed identity.
		 * @return void
		 */
		public function testRandomAliasIsNotFixed(): void {
			self::assertNotSame(EventBindingNaming::randomAlias(), EventBindingNaming::randomAlias());
		}

		/**
		 * The fixed prefix leaves the complete alias available to the inspector.
		 * @return void
		 */
		public function testTriggerNamePrefixMatchesTriggerName(): void {
			$table = str_repeat('t', 49);
			$prefix = EventBindingNaming::triggerNamePrefix($table);
			self::assertSame($prefix . 'audit_trigger', EventBindingNaming::triggerName($table, 'audit_trigger'));
		}
	}
