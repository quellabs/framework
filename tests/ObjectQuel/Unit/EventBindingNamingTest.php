<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
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
			self::assertSame('eq_users_audit_trigger', EventBindingNaming::triggerName('users', 'audit_trigger'));
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
		 * A name long enough to exceed every engine's identifier limit is truncated with a
		 * stable hash suffix, not just cut off.
		 * @return void
		 */
		public function testLongNameIsTruncatedWithHashSuffix(): void {
			$name = EventBindingNaming::triggerName(
				'a_very_long_physical_table_name_that_exceeds_every_engine_limit',
				'a_very_long_alias_as_well'
			);

			self::assertLessThanOrEqual(60, strlen($name));
			self::assertMatchesRegularExpression('/_[0-9a-f]{8}$/', $name);
		}

		/**
		 * Two different long identities that would truncate to the same prefix still produce
		 * distinct names, because the hash is over the full identity, not the truncated prefix.
		 * @return void
		 */
		public function testLongNamesWithSamePrefixStayDistinct(): void {
			$base = str_repeat('x', 80);
			$first = EventBindingNaming::triggerName($base . '_one', 'f');
			$second = EventBindingNaming::triggerName($base . '_two', 'f');
			self::assertNotSame($first, $second);
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
		 * The prefix recovers exactly the portion a trigger name built from it would have after
		 * the alias — used to read an alias back out of an existing name (see
		 * RoutineDependencyInspector::listBindings()).
		 * @return void
		 */
		public function testTriggerNamePrefixMatchesTriggerName(): void {
			$prefix = EventBindingNaming::triggerNamePrefix('users');
			self::assertSame($prefix . 'audit_trigger', EventBindingNaming::triggerName('users', 'audit_trigger'));
		}
	}
