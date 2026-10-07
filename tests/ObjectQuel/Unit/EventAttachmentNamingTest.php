<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AttachmentEvent;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventAttachmentNaming;

	/**
	 * Deterministic trigger/helper naming (objectquel-equel-triggers-design.md,
	 * "Attachment identity and removal").
	 */
	class EventAttachmentNamingTest extends TestCase {

		/**
		 * @return void
		 */
		public function testShortNameIsNotTruncated(): void {
			self::assertSame('eq_users_replace_audit_user', EventAttachmentNaming::triggerName('users', AttachmentEvent::Replace, 'audit_user'));
		}

		/**
		 * @return void
		 */
		public function testSameIdentityIsAlwaysTheSameName(): void {
			$first = EventAttachmentNaming::triggerName('users', AttachmentEvent::Append, 'on_created');
			$second = EventAttachmentNaming::triggerName('users', AttachmentEvent::Append, 'on_created');
			self::assertSame($first, $second);
		}

		/**
		 * A different event or routine produces a different name for the same table.
		 * @return void
		 */
		public function testDistinctTriplesProduceDistinctNames(): void {
			$append = EventAttachmentNaming::triggerName('users', AttachmentEvent::Append, 'f');
			$replace = EventAttachmentNaming::triggerName('users', AttachmentEvent::Replace, 'f');
			$other = EventAttachmentNaming::triggerName('users', AttachmentEvent::Append, 'g');
			self::assertNotSame($append, $replace);
			self::assertNotSame($append, $other);
		}

		/**
		 * A name long enough to exceed every engine's identifier limit is truncated with a
		 * stable hash suffix, not just cut off.
		 * @return void
		 */
		public function testLongNameIsTruncatedWithHashSuffix(): void {
			$name = EventAttachmentNaming::triggerName(
				'a_very_long_physical_table_name_that_exceeds_every_engine_limit',
				AttachmentEvent::Replace,
				'a_very_long_routine_name_as_well'
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
			$first = EventAttachmentNaming::triggerName($base . '_one', AttachmentEvent::Replace, 'f');
			$second = EventAttachmentNaming::triggerName($base . '_two', AttachmentEvent::Replace, 'f');
			self::assertNotSame($first, $second);
		}

		/**
		 * The PostgreSQL helper name is derived from the trigger name and stays within limits too.
		 * @return void
		 */
		public function testHelperFunctionNameIsDerivedFromTriggerName(): void {
			$trigger = EventAttachmentNaming::triggerName('users', AttachmentEvent::Replace, 'audit_user');
			$helper = EventAttachmentNaming::helperFunctionName($trigger);

			self::assertSame("{$trigger}_fn", $helper);
			self::assertLessThanOrEqual(60, strlen($helper));
		}
	}
