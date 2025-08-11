<?php
	
	namespace App\Entities;
	
	use Quellabs\ObjectQuel\Annotations\Orm;
	
	/**
	 * @Orm\Table(name="location_logs")
	 * @Orm\Index(name="idx_user_timestamp", columns={"userId", "timestamp"})
	 */
	class LocationLog {
		
		/**
		 * @Orm\Column(name="id", type="integer", length=11, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		private ?int $id = null;
		
		/**
		 * @Orm\Column(name="user_id", type="integer", length=11)
		 */
		private int $userId;
		
		/**
		 * @Orm\Column(name="latitude", type="decimal", precision=10, scale=8)
		 */
		private float $latitude;
		
		/**
		 * @Orm\Column(name="longitude", type="decimal", precision=11, scale=8)
		 */
		private float $longitude;
		
		/**
		 * @Orm\Column(name="accuracy", type="float", nullable=true)
		 */
		private ?float $accuracy = null;
		
		/**
		 * @Orm\Column(name="altitude", type="float", nullable=true)
		 */
		private ?float $altitude = null;
		
		/**
		 * @Orm\Column(name="heading", type="float", nullable=true)
		 */
		private ?float $heading = null;
		
		/**
		 * @Orm\Column(name="speed", type="float", nullable=true)
		 */
		private ?float $speed = null;
		
		/**
		 * @Orm\Column(name="metadata", type="json", nullable=true)
		 */
		private array $metadata = [];
		
		/**
		 * @Orm\Column(name="timestamp", type="datetime")
		 */
		private \DateTime $timestamp;
		
		public function getId(): ?int {
			return $this->id;
		}
		
		public function getUserId(): int {
			return $this->userId;
		}
		
		public function setUserId(int $userId): void {
			$this->userId = $userId;
		}
		
		public function getLatitude(): float {
			return $this->latitude;
		}
		
		public function setLatitude(float $latitude): void {
			$this->latitude = $latitude;
		}
		
		public function getLongitude(): float {
			return $this->longitude;
		}
		
		public function setLongitude(float $longitude): void {
			$this->longitude = $longitude;
		}
		
		public function getAccuracy(): ?float {
			return $this->accuracy;
		}
		
		public function setAccuracy(?float $accuracy): void {
			$this->accuracy = $accuracy;
		}
		
		public function getAltitude(): ?float {
			return $this->altitude;
		}
		
		public function setAltitude(?float $altitude): void {
			$this->altitude = $altitude;
		}
		
		public function getHeading(): ?float {
			return $this->heading;
		}
		
		public function setHeading(?float $heading): void {
			$this->heading = $heading;
		}
		
		public function getSpeed(): ?float {
			return $this->speed;
		}
		
		public function setSpeed(?float $speed): void {
			$this->speed = $speed;
		}
		
		public function getMetadata(): array {
			return $this->metadata;
		}
		
		public function setMetadata(array $metadata): void {
			$this->metadata = $metadata;
		}
		
		public function getTimestamp(): \DateTime {
			return $this->timestamp;
		}
		
		public function setTimestamp(\DateTime $timestamp): void {
			$this->timestamp = $timestamp;
		}
	}
