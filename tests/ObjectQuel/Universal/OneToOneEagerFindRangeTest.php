<?php

	namespace Quellabs\ObjectQuel\Tests\Universal;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;

	use App\Entities\AccountEntity;
	use App\Entities\CredentialEntity;

	/**
	 * Regression test: QueryBuilder::getRelationRanges() must still eagerly join
	 * an owning OneToOne relation when its referencedColumn is explicitly set.
	 *
	 * CredentialEntity::$account is the owning side (fetch="EAGER",
	 * referencedColumn="id" explicitly set — see CredentialEntity.php).
	 * AccountEntity::$credential is the matching @InverseOf.
	 *
	 * Before the fix, QueryBuilder::addRanges() treated any non-null
	 * referencedColumn as an inverse-side marker and skipped the eager join,
	 * silently downgrading this relation to lazy loading on every find().
	 */
	class OneToOneEagerFindRangeTest extends ObjectQuelTestCase {

		protected array $truncateTables = ['credentials', 'accounts'];

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO accounts (id, label) VALUES (1, 'primary')");
			$this->exec("INSERT INTO credentials (id, secret, account_id) VALUES (1, 'secret-A', 1)");
		}

		public function testFindEagerlyJoinsOwningOneToOneWithExplicitReferencedColumn(): void {
			$account = $this->em->find(AccountEntity::class, 1);

			$this->assertNotNull($account);
			$this->assertNotNull($account->credential);

			// A genuinely eager-joined relation is hydrated directly from the
			// result set, never as a lazy proxy awaiting a follow-up query.
			$this->assertNotInstanceOf(
				ProxyInterface::class,
				$account->credential,
				'account->credential should be eagerly hydrated, not a lazy proxy'
			);
			$this->assertSame('secret-A', $account->credential->getSecret());
		}
	}
