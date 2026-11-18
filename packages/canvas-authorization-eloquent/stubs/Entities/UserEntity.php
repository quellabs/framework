<?php

	namespace App\Entities;

	use Illuminate\Database\Eloquent\Model;
	use Quellabs\CanvasAuthorization\Contracts\AccountEligibilityInterface;

	/**
	 * Unique, not a plain index: without a DB-enforced constraint, two
	 * concurrent registrations for the same username can both pass
	 * AuthenticationController::findUser()'s read-then-write check and
	 * both insert, producing two accounts with the same login identifier.
	 * Add that unique index on users.username in your migration.
	 */
	class UserEntity extends Model implements AccountEligibilityInterface {

		/**
		 * @var string
		 */
		protected $table = 'users';

		/**
		 * @var bool
		 */
		public $timestamps = false;

		/**
		 * @var array<int, string>
		 */
		protected $fillable = ['name', 'username', 'password', 'banned'];

		/**
		 * @var array<string, string>
		 */
		protected $casts = ['banned' => 'boolean'];

		/**
		 * Get id
		 * @return int
		 */
		public function getId(): int {
			return $this->id;
		}

		/**
		 * Get name
		 * @return string
		 */
		public function getName(): string {
			return $this->name;
		}

		/**
		 * Set name
		 * @param string $name
		 * @return $this
		 */
		public function setName(string $name): self {
			$this->name = $name;
			return $this;
		}

		/**
		 * Get username
		 * @return string
		 */
		public function getUsername(): string {
			return $this->username;
		}

		/**
		 * Set username
		 * @param string $username
		 * @return $this
		 */
		public function setUsername(string $username): self {
			$this->username = $username;
			return $this;
		}

		/**
		 * Get password
		 * @return string
		 */
		public function getPassword(): string {
			return $this->password;
		}

		/**
		 * Set password
		 * @param string $password
		 * @return $this
		 */
		public function setPassword(string $password): self {
			$this->password = $password;
			return $this;
		}

		/**
		 * Returns true if the user was banned, false if not
		 * @return bool
		 */
		public function isBanned(): bool {
			return (bool)$this->banned;
		}

		/**
		 * Sets banned status
		 * @param bool $banned
		 * @return $this
		 */
		public function setBanned(bool $banned): self {
			$this->banned = $banned;
			return $this;
		}
	}
