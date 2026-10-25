<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;
	use Quellabs\ObjectQuel\Tests\Fixtures\NonPkReferenceEntities\NonPkRefProfileEntity;

	/**
	 * Regression coverage for a lazy ManyToOne/OneToOne whose referencedColumn
	 * names a property other than the target's primary key.
	 *
	 * RelationshipLoader::findOrCreateProxy() previously always treated the FK
	 * value as if it were the target's primary key: it seeded the proxy's PK
	 * property with that value and had its initializer call find() (a PK-only
	 * lookup) using it directly. When referencedColumn named a non-PK property
	 * (e.g. 'email'), that silently corrupted the proxy's PK with the
	 * referenced value and queried the wrong column, so the relation resolved
	 * to no data (or, by coincidence, the wrong row) with no error at all.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] — see
	 * RelationshipCascadeForeignKeyTest for why a second EntityManager can't be
	 * constructed in this process.
	 */
	class LazyProxyNonPrimaryKeyReferenceTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute('DELETE FROM non_pk_ref_profiles');
			$adapter->execute('DELETE FROM non_pk_ref_users');

			// Two users, so a lookup that wrongly treats the referenced email as a
			// primary key — or matches the wrong row — is caught, not masked by
			// there being only one possible row to (accidentally) match.
			$adapter->execute("INSERT INTO non_pk_ref_users (id, email) VALUES (1, 'alice@example.com')");
			$adapter->execute("INSERT INTO non_pk_ref_users (id, email) VALUES (2, 'bob@example.com')");
			$adapter->execute("INSERT INTO non_pk_ref_profiles (id, user_email) VALUES (1, 'bob@example.com')");

			self::em()->getUnitOfWork()->clear();
		}

		public function testLazyProxyResolvesByTheReferencedNonPrimaryKeyProperty(): void {
			$em = self::em();

			$profile = $em->find(NonPkRefProfileEntity::class, 1);
			self::assertNotNull($profile);

			// Captured once: triggering the lazy load below replaces $profile->user
			// with the fully resolved, managed entity, so later assertions must use
			// this reference rather than re-reading $profile->user.
			$proxy = $profile->user;
			self::assertInstanceOf(ProxyInterface::class, $proxy);
			self::assertFalse($proxy->isInitialized());

			// Must resolve to Bob (matched by email), never Alice, and never a proxy
			// whose 'id' was corrupted into holding the email string.
			self::assertSame('bob@example.com', $proxy->getEmail());
			self::assertTrue($proxy->isInitialized());
			self::assertSame(2, $profile->user->getId());
		}

		public function testLazyProxyReturnsNullWhenNoRowMatchesTheReferencedProperty(): void {
			$em = self::em();

			$adapter = $em->getConnection();
			$adapter->execute("UPDATE non_pk_ref_profiles SET user_email = 'nobody@example.com' WHERE id = 1");
			$em->getUnitOfWork()->clear();

			$profile = $em->find(NonPkRefProfileEntity::class, 1);
			self::assertNotNull($profile);

			$proxy = $profile->user;
			self::assertInstanceOf(ProxyInterface::class, $proxy);
			self::assertNull($proxy->getEmail());
		}
	}
