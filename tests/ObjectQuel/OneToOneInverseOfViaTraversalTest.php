<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use App\Entities\AccountEntity;
	use App\Entities\CredentialEntity;
	
	/**
	 * Tests via-clause traversal across a OneToOne relationship in both directions.
	 *
	 * Owning side:   CredentialEntity::$account  @OneToOne(... referencedColumn="id")  [no localColumn]
	 * Inverse side:  AccountEntity::$credential  @InverseOf(relation="account")
	 *
	 * The owning OneToOne deliberately omits localColumn, so the FK column is resolved
	 * from the default convention. On the inverse path the via property is "credential"
	 * but the FK-holding column belongs to the owning property "account" (-> "accountId"
	 * -> column "account_id"). The default base must therefore be the resolved owning
	 * property, not the via-clause property.
	 *
	 * Regression guard: before the fix, the OneToOne handler defaulted the FK base to the
	 * via-clause property name ("credential" -> "credentialId"), which has no matching
	 * column and produces a wrong/broken JOIN on the inverse path. The ManyToOne handler
	 * already used the owning property; this brings OneToOne into line.
	 *
	 * Mirrors InverseOfViaTraversalTest (which covers the ManyToOne equivalent).
	 */
	class OneToOneInverseOfViaTraversalTest extends ObjectQuelTestCase {
		
		// children (FK holder) before parents
		protected array $truncateTables = ['credentials', 'accounts'];
		
		protected function seedFixtures(): void {
			$this->exec("INSERT INTO accounts (id, label) VALUES (1, 'primary')");
			$this->exec("INSERT INTO accounts (id, label) VALUES (2, 'secondary')");
			
			// One credential per account (true 1:1). account_id is the FK column.
			$this->exec("INSERT INTO credentials (id, secret, account_id) VALUES (1, 'secret-A', 1)");
			$this->exec("INSERT INTO credentials (id, secret, account_id) VALUES (2, 'secret-B', 2)");
		}
		
		// -------------------------------------------------------------------------
		// Inverse path: range of c via a.credential  (the fixed code path)
		// -------------------------------------------------------------------------
		
		public function testOneToOneInverseViaReturnsAllPairs(): void {
			// 2 accounts, each with exactly one credential -> 2 rows.
			$result = $this->em->executeQuery("
			range of a is AccountEntity
			range of c is CredentialEntity via a.credential
			retrieve (a, c)
			");
			
			$this->assertCount(2, $result);
		}
		
		public function testOneToOneInverseViaReturnsCorrectEntityTypes(): void {
			$result = $this->em->executeQuery("
			range of a is AccountEntity
			range of c is CredentialEntity via a.credential
			retrieve (a, c)
			");
			
			foreach ($result as $row) {
				$this->assertInstanceOf(AccountEntity::class, $row['a']);
				$this->assertInstanceOf(CredentialEntity::class, $row['c']);
			}
		}
		
		public function testOneToOneInverseViaJoinsMatchingAccountOnly(): void {
			// Core regression assertion. With the pre-fix default base ("credentialId")
			// the JOIN column would not exist / would not match, so either this query
			// errors or pairs accounts with the wrong credential. Each row must pair a
			// credential with the account that actually owns it.
			$result = $this->em->executeQuery("
			range of a is AccountEntity
			range of c is CredentialEntity via a.credential
			retrieve (a.id, c.id)
			");
			
			$ownership = [1 => 1, 2 => 2]; // credential id => owning account id
			
			$this->assertCount(2, $result);
			
			foreach ($result as $row) {
				$expectedAccount = $ownership[$row['c.id']];
				$this->assertSame(
					$expectedAccount,
					$row['a.id'],
					"Credential {$row['c.id']} should belong to account $expectedAccount, got {$row['a.id']}"
				);
			}
		}
		
		public function testOneToOneInverseViaFiltersOnJoinedSide(): void {
			$result = $this->em->executeQuery("
			range of a is AccountEntity
			range of c is CredentialEntity via a.credential
			retrieve (a.label, c.secret)
			where a.label = 'primary'
			");
			
			$this->assertCount(1, $result);
			$this->assertSame('primary',  $result[0]['a.label']);
			$this->assertSame('secret-A', $result[0]['c.secret']);
		}
		
		public function testAccountWithNoCredentialProducesNullRow(): void {
			// via-clause joins are LEFT JOINs: an account with no credential still
			// appears, paired with a null credential.
			$this->exec("INSERT INTO accounts (id, label) VALUES (3, 'orphan')");
			
			$result = $this->em->executeQuery("
			range of a is AccountEntity
			range of c is CredentialEntity via a.credential
			retrieve (a, c)
			where a.label = 'orphan'
			");
			
			$this->assertCount(1, $result);
			$this->assertInstanceOf(AccountEntity::class, $result[0]['a']);
			$this->assertNull($result[0]['c']);
		}
		
		// -------------------------------------------------------------------------
		// Direct path: range of a via c.account  (always correct; parity coverage)
		// -------------------------------------------------------------------------
		
		public function testOneToOneDirectViaJoinsCorrectly(): void {
			// Forward direction. Here the via property "account" IS the owning property,
			// so the default base was correct even before the fix. Included so both
			// directions of the OneToOne are covered.
			$result = $this->em->executeQuery("
			range of c is CredentialEntity
			range of a is AccountEntity via c.account
			retrieve (c.id, a.id)
			sort by c.id asc
			");
			
			$ownership = [1 => 1, 2 => 2]; // credential id => account id
			
			$this->assertCount(2, $result);
			
			foreach ($result as $row) {
				$expectedAccount = $ownership[$row['c.id']];
				$this->assertSame(
					$expectedAccount,
					$row['a.id'],
					"Credential {$row['c.id']} should join account $expectedAccount, got {$row['a.id']}"
				);
			}
		}
	}