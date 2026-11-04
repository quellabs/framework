<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Security\Foundation\CsrfTokenManager;
	use Symfony\Component\HttpFoundation\Session\SessionBagInterface;
	use Symfony\Component\HttpFoundation\Session\SessionInterface;
	use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;

	/**
	 * In-memory SessionInterface stand-in; CsrfTokenManager only uses get()/set().
	 */
	class CsrfTokenManagerTestSession implements SessionInterface {
		/** @var array<string, mixed> */
		private array $attributes = [];

		/**
		 * @return bool
		 */
		public function start(): bool {
			return true;
		}

		/**
		 * @return string
		 */
		public function getId(): string {
			return 'test-session';
		}

		/**
		 * @param string $id
		 * @return void
		 */
		public function setId(string $id): void {
		}

		/**
		 * @return string
		 */
		public function getName(): string {
			return 'test';
		}

		/**
		 * @param string $name
		 * @return void
		 */
		public function setName(string $name): void {
		}

		/**
		 * @param int|null $lifetime
		 * @return bool
		 */
		public function invalidate(?int $lifetime = null): bool {
			$this->attributes = [];
			return true;
		}

		/**
		 * @param bool $destroy
		 * @param int|null $lifetime
		 * @return bool
		 */
		public function migrate(bool $destroy = false, ?int $lifetime = null): bool {
			return true;
		}

		/**
		 * @return void
		 */
		public function save(): void {
		}

		/**
		 * @param string $name
		 * @return bool
		 */
		public function has(string $name): bool {
			return isset($this->attributes[$name]);
		}

		/**
		 * @param string $name
		 * @param mixed $default
		 * @return mixed
		 */
		public function get(string $name, mixed $default = null): mixed {
			return $this->attributes[$name] ?? $default;
		}

		/**
		 * @param string $name
		 * @param mixed $value
		 * @return void
		 */
		public function set(string $name, mixed $value): void {
			$this->attributes[$name] = $value;
		}

		/**
		 * @return array
		 */
		public function all(): array {
			return $this->attributes;
		}

		/**
		 * @param array $attributes
		 * @return void
		 */
		public function replace(array $attributes): void {
			$this->attributes = $attributes;
		}

		/**
		 * @param string $name
		 * @return mixed
		 */
		public function remove(string $name): mixed {
			$value = $this->attributes[$name] ?? null;
			unset($this->attributes[$name]);
			return $value;
		}

		/**
		 * @return void
		 */
		public function clear(): void {
			$this->attributes = [];
		}

		/**
		 * @return bool
		 */
		public function isStarted(): bool {
			return true;
		}

		/**
		 * @param SessionBagInterface $bag
		 * @return void
		 */
		public function registerBag(SessionBagInterface $bag): void {
		}

		/**
		 * @param string $name
		 * @return SessionBagInterface
		 */
		public function getBag(string $name): SessionBagInterface {
			throw new \LogicException('Not implemented in test stub.');
		}

		/**
		 * @return MetadataBag
		 */
		public function getMetadataBag(): MetadataBag {
			return new MetadataBag();
		}
	}

	/**
	 * CsrfTokenManager::validateToken() must compare tokens with hash_equals()
	 * rather than in_array(), so a mismatch doesn't leak timing information
	 * about how many leading bytes matched.
	 */
	class CsrfTokenManagerTest extends TestCase {
		/**
		 * @return void
		 */
		public function testValidTokenPassesAndIsSingleUse(): void {
			$manager = new CsrfTokenManager(new CsrfTokenManagerTestSession());
			$token = $manager->generateToken('delete_user');

			$this->assertTrue($manager->validateToken($token, 'delete_user'));

			// Single-use: the same token must not validate twice
			$this->assertFalse($manager->validateToken($token, 'delete_user'));
		}

		/**
		 * @return void
		 */
		public function testUnknownTokenFails(): void {
			$manager = new CsrfTokenManager(new CsrfTokenManagerTestSession());
			$manager->generateToken('delete_user');

			$this->assertFalse($manager->validateToken(bin2hex(random_bytes(32)), 'delete_user'));
		}

		/**
		 * A token generated for one intention must not validate under another.
		 * @return void
		 */
		public function testTokenIsScopedToItsIntention(): void {
			$manager = new CsrfTokenManager(new CsrfTokenManagerTestSession());
			$token = $manager->generateToken('delete_user');

			$this->assertFalse($manager->validateToken($token, 'edit_user'));
		}

		/**
		 * A token that only matches another stored token in its leading bytes
		 * must still be rejected outright, not partially accepted.
		 * @return void
		 */
		public function testPartiallyMatchingTokenIsRejected(): void {
			$manager = new CsrfTokenManager(new CsrfTokenManagerTestSession());
			$token = $manager->generateToken('delete_user');

			$tampered = substr($token, 0, -2) . 'zz';
			$this->assertFalse($manager->validateToken($tampered, 'delete_user'));

			// The original token must still be valid afterwards
			$this->assertTrue($manager->validateToken($token, 'delete_user'));
		}
	}
