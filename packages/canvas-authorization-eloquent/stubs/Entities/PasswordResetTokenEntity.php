<?php

	namespace App\Entities;

	use Illuminate\Database\Eloquent\Model;

	/**
	 * A single-use password reset token, issued by
	 * AuthenticationController::processForgotPassword() and consumed by
	 * AuthenticationController::processResetPassword().
	 *
	 * Only a hash of the token is ever stored — token_hash is
	 * hash('sha256', $rawToken) — so a compromised database never yields a
	 * usable token. The raw token exists only in memory long enough to be
	 * handed to PasswordResetNotifierInterface::send().
	 *
	 * Deliberately no updated_at: a token is either unused (created_at only)
	 * or consumed exactly once (used_at set), never repeatedly modified.
	 */
	class PasswordResetTokenEntity extends Model {

		/**
		 * @var string
		 */
		const CREATED_AT = 'created_at';

		/**
		 * @var string|null
		 */
		const UPDATED_AT = null;

		/**
		 * @var string
		 */
		protected $table = 'password_reset_tokens';

		/**
		 * @var bool
		 */
		public $timestamps = true;

		/**
		 * @var array<int, string>
		 */
		protected $fillable = ['user_id', 'token_hash', 'expires_at', 'used_at'];

		/**
		 * @var array<string, string>
		 */
		protected $casts = [
			'expires_at' => 'datetime',
			'used_at'    => 'datetime',
		];

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
			return $this->user_id;
		}

		/**
		 * Sets the userId value
		 * @param int $userId
		 * @return $this
		 */
		public function setUserId(int $userId): self {
			$this->user_id = $userId;
			return $this;
		}

		/**
		 * Gets the tokenHash value
		 * @return string
		 */
		public function getTokenHash(): string {
			return $this->token_hash;
		}

		/**
		 * Sets the tokenHash value
		 * @param string $tokenHash
		 * @return $this
		 */
		public function setTokenHash(string $tokenHash): self {
			$this->token_hash = $tokenHash;
			return $this;
		}

		/**
		 * Gets the expiresAt value
		 * @return \DateTime
		 */
		public function getExpiresAt(): \DateTime {
			return $this->expires_at;
		}

		/**
		 * Sets the expiresAt value
		 * @param \DateTime $expiresAt
		 * @return $this
		 */
		public function setExpiresAt(\DateTime $expiresAt): self {
			$this->expires_at = $expiresAt;
			return $this;
		}

		/**
		 * Gets the usedAt value
		 * @return \DateTime|null
		 */
		public function getUsedAt(): ?\DateTime {
			return $this->used_at;
		}

		/**
		 * Sets the usedAt value
		 * @param \DateTime|null $usedAt
		 * @return $this
		 */
		public function setUsedAt(?\DateTime $usedAt): self {
			$this->used_at = $usedAt;
			return $this;
		}

		/**
		 * Gets the createdAt value
		 * @return \DateTime
		 */
		public function getCreatedAt(): \DateTime {
			return $this->created_at;
		}
	}
