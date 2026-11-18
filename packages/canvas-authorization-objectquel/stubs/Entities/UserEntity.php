<?php
	
	namespace App\Entities;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\CanvasAuthorization\Contracts\AccountEligibilityInterface;

	/**
	 * Unique, not a plain index: without a DB-enforced constraint, two
	 * concurrent registrations for the same username can both pass
	 * AuthenticationController::findUser()'s read-then-write check and
	 * both insert, producing two accounts with the same login identifier.
	 * @Orm\UniqueIndex(name="uidx_username", columns={"username"})
	 * @Orm\Table(name="users")
	 */
	class UserEntity implements AccountEligibilityInterface {
		
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;
		
		/**
		 * @Orm\Column(name="name", type="string", limit=255)
		 */
		protected string $name;

		/**
		 * @Orm\Column(name="username", type="string", limit=255)
		 */
		protected string $username;
		
		/**
		 * @Orm\Column(name="password", type="string", limit=255)
		 */
		protected string $password;
		
		/**
		 * @Orm\Column(name="banned", type="boolean")
		 */
		protected bool $banned = false;
		
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
			return $this->banned;
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