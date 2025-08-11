<?php
	namespace App\Entities;
	
	use Quellabs\ObjectQuel\Annotations\Orm;
	
	/**
	 * @Orm\Table(name="fence_events")
	 * @Orm\Index(name="idx_user_fence", columns={"user_id", "fence_id"})
	 * @Orm\Index(name="idx_event_timestamp", columns={"timestamp"})
	 */
	class FenceEvent {
		
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
		 * @Orm\Column(name="fence_id", type="integer", length=11)
		 */
		private int $fenceId;
		
		/**
		 * @Orm\Column(name="event_type", type="string", length=10)
		 */
		private string $eventType; // 'enter' or 'exit'
		
		/**
		 * @Orm\Column(name="latitude", type="decimal", precision=10, scale=8)
		 */
		private float $latitude;
		
		/**
		 * @Orm\Column(name="longitude", type="decimal", precision=11, scale=8)
		 */
		private float $longitude;
		
		/**
		 * @Orm\Column(name="metadata", type="json", nullable=true)
		 */
		private array $metadata = [];
		
		/**
		 * @Orm\Column(name="timestamp", type="datetime")
		 */
		private \DateTime $timestamp;
		
		/**
		 * @Orm\ManyToOne(targetEntity="GeoFence", inversedBy="events")
		 * @Orm\RequiredRelation
		 */
		private ?GeoFence $fence = null;
		
		public function getId(): ?int {
			return $this->id;
		}
		
		public function getUserId(): int {
			return $this->userId;
		}
		
		public function setUserId(int $userId): void {
			$this->userId = $userId;
		}
		
		public function getFenceId(): int {
			return $this->fenceId;
		}
		
		public function setFenceId(int $fenceId): void {
			$this->fenceId = $fenceId;
		}
		
		public function getEventType(): string {
			return $this->eventType;
		}
		
		public function setEventType(string $eventType): void {
			$this->eventType = $eventType;
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
		
		public function getFence(): ?GeoFence {
			return $this->fence;
		}
		
		public function setFence(?GeoFence $fence): void {
			$this->fence = $fence;
			if ($fence) {
				$this->fenceId = $fence->getId();
			}
		}
	}
