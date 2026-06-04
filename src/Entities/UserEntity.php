<?php
	
	namespace App\Entities;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Index;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;
	
	/**
	 * @Orm\Index(name="idx_username", columns={"username"})
	 * @Orm\Table(name="users")
	 */
	class UserEntity {
		
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;
		
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
		 * @Orm\InverseOf(targetEntity=PostEntity::class, via="user")
		 */
		public CollectionInterface $posts;
		
		public function __construct() {
			$this->posts = new Collection();
		}
		
		/**
		 * Get id
		 * @return int
		 */
		public function getId(): int {
			return $this->id;
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
		 * @param string $banned
		 * @return $this
		 */
		public function setBanned(string $banned): self {
			$this->banned = $banned;
			return $this;
		}
	}