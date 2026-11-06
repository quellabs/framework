<?php

	namespace App\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\LifecycleAware;
	use Quellabs\ObjectQuel\Annotations\Orm\PrePersist;

	/**
	 * A single-use password reset token, issued by
	 * AuthenticationController::processForgotPassword() and consumed by
	 * AuthenticationController::processResetPassword().
	 *
	 * Only a hash of the token is ever stored — tokenHash is
	 * hash('sha256', $rawToken) — so a compromised database never yields a
	 * usable token. The raw token exists only in memory long enough to be
	 * handed to PasswordResetNotifierInterface::send().
	 *
	 * Deliberately not @Orm\LifecycleAware for updatedAt: a token is either
	 * unused (createdAt only) or consumed exactly once (usedAt set), never
	 * repeatedly modified.
	 *
	 * @Orm\Table(name="password_reset_tokens")
	 * @Orm\UniqueIndex(name="uidx_password_reset_tokens_hash", columns={"tokenHash"})
	 * @Orm\LifecycleAware
	 */
	class PasswordResetTokenEntity {

		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * The account this token grants a password reset for.
		 * @Orm\Column(name="user_id", type="integer", unsigned=true, nullable=false)
		 */
		protected int $userId;

		/**
		 * hash('sha256', $rawToken) — never the raw token itself. See class
		 * docblock.
		 * @Orm\Column(name="token_hash", type="string", limit=64, nullable=false)
		 */
		protected string $tokenHash;

		/**
		 * Moment after which this token is no longer valid, regardless of
		 * whether it was ever used.
		 * @Orm\Column(name="expires_at", type="datetime", nullable=false)
		 */
		protected \DateTime $expiresAt;

		/**
		 * Set the moment this token is consumed. Null means still unused.
		 * A token is valid only when this is null AND expiresAt is in the
		 * future.
		 * @Orm\Column(name="used_at", type="datetime", nullable=true)
		 */
		protected ?\DateTime $usedAt = null;

		/**
		 * @Orm\Column(name="created_at", type="datetime", nullable=false)
		 */
		protected \DateTime $createdAt;

		/**
		 * Stamps created_at right before the first INSERT.
		 * @Orm\PrePersist
		 */
		public function onPrePersist(): void {
			$this->createdAt = new \DateTime();
		}

		/**
		 * Gets the id value
		 * @return int|null
		 */
		public function getId(): ?int {
			return $this->id;
		}

		/**
		 * Gets the userId value
		 * @return int
		 */
		public function getUserId(): int {
			return $this->userId;
		}

		/**
		 * Sets the userId value
		 * @param int $userId
		 * @return $this
		 */
		public function setUserId(int $userId): self {
			$this->userId = $userId;
			return $this;
		}

		/**
		 * Gets the tokenHash value
		 * @return string
		 */
		public function getTokenHash(): string {
			return $this->tokenHash;
		}

		/**
		 * Sets the tokenHash value
		 * @param string $tokenHash
		 * @return $this
		 */
		public function setTokenHash(string $tokenHash): self {
			$this->tokenHash = $tokenHash;
			return $this;
		}

		/**
		 * Gets the expiresAt value
		 * @return \DateTime
		 */
		public function getExpiresAt(): \DateTime {
			return $this->expiresAt;
		}

		/**
		 * Sets the expiresAt value
		 * @param \DateTime $expiresAt
		 * @return $this
		 */
		public function setExpiresAt(\DateTime $expiresAt): self {
			$this->expiresAt = $expiresAt;
			return $this;
		}

		/**
		 * Gets the usedAt value
		 * @return \DateTime|null
		 */
		public function getUsedAt(): ?\DateTime {
			return $this->usedAt;
		}

		/**
		 * Sets the usedAt value
		 * @param \DateTime|null $usedAt
		 * @return $this
		 */
		public function setUsedAt(?\DateTime $usedAt): self {
			$this->usedAt = $usedAt;
			return $this;
		}

		/**
		 * Gets the createdAt value
		 * @return \DateTime
		 */
		public function getCreatedAt(): \DateTime {
			return $this->createdAt;
		}
	}
