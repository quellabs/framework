<?php
	
	namespace App\Entities;
	
	use Quellabs\ObjectQuel\Annotations\Orm;
	
	/**
	 * GeoFence entity represents a geographical boundary for location-based services.
	 * Supports both circular and polygonal fence types with customizable metadata.
	 * @Orm\Table(name="geo_fences")
	 */
	class GeoFence implements \JsonSerializable {
		
		/**
		 * Primary key identifier for the geo fence
		 * @Orm\Column(name="id", type="integer", length=11, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		private ?int $id = null;
		
		/**
		 * Human-readable name for the geo fence (e.g., "Downtown Office Area")
		 * @Orm\Column(name="name", type="string", length=255)
		 */
		private string $name;
		
		/**
		 * Type of geometric fence - either 'circle' for circular boundaries or 'polygon' for custom shapes
		 * @Orm\Column(name="type", type="string", length=20)
		 */
		private string $type; // 'circle' or 'polygon'
		
		/**
		 * JSON structure containing geometric data:
		 * - For circles: {'center': {'lat': float, 'lng': float}, 'radius': int}
		 * - For polygons: {'coordinates': [[lat, lng], [lat, lng], ...]}
		 * @Orm\Column(name="geometry", type="json")
		 */
		private array $geometry = [];
		
		/**
		 * Flag indicating whether this geo fence is currently active/enabled
		 * @Orm\Column(name="active", type="boolean", default=true)
		 */
		private bool $active = true;
		
		/**
		 * Additional custom data associated with the fence (e.g., alerts, descriptions, permissions)
		 * @Orm\Column(name="metadata", type="json", nullable=true)
		 */
		private array $metadata = [];
		
		/**
		 * Timestamp when the geo fence was created
		 * @Orm\Column(name="created_at", type="datetime")
		 */
		private \DateTime $createdAt;
		
		/**
		 * Timestamp when the geo fence was last updated
		 * @Orm\Column(name="updated_at", type="datetime")
		 */
		private \DateTime $updatedAt;
		
		/**
		 * Get the unique identifier of the geo fence
		 * @return int|null The ID or null if not persisted yet
		 */
		public function getId(): ?int {
			return $this->id;
		}
		
		/**
		 * Get the display name of the geo fence
		 * @return string The fence name
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * Set the display name of the geo fence
		 * @param string $name The new name for the fence
		 */
		public function setName(string $name): void {
			$this->name = $name;
		}
		
		/**
		 * Get the geometric type of the fence
		 * @return string Either 'circle' or 'polygon'
		 */
		public function getType(): string {
			return $this->type;
		}
		
		/**
		 * Set the geometric type of the fence
		 * @param string $type Must be 'circle' or 'polygon'
		 */
		public function setType(string $type): void {
			$this->type = $type;
		}
		
		/**
		 * Get the raw geometry data as an associative array
		 * @return array The geometry configuration
		 */
		public function getGeometry(): array {
			return $this->geometry;
		}
		
		/**
		 * Set the raw geometry data
		 * @param array $geometry The geometry configuration array
		 */
		public function setGeometry(array $geometry): void {
			$this->geometry = $geometry;
		}
		
		/**
		 * Get the center latitude for circular fences
		 * @return float|null Latitude or null if not a circle or not set
		 */
		public function getCenterLat(): ?float {
			if (!$this->isCircle()) {
				return null;
			}
			
			return $this->geometry['center']['lat'] ?? null;
		}
		
		/**
		 * Get the center longitude for circular fences
		 * @return float|null Longitude or null if not a circle or not set
		 */
		public function getCenterLng(): ?float {
			if (!$this->isCircle()) {
				return null;
			}
			
			return $this->geometry['center']['lng'] ?? null;
		}
		
		/**
		 * Get the radius in meters for circular fences
		 * @return int|null Radius in meters or null if not a circle or not set
		 */
		public function getRadiusMeters(): ?int {
			if (!$this->isCircle()) {
				return null;
			}
			
			return $this->geometry['radius'] ?? null;
		}
		
		/**
		 * Configure this fence as a circle with center point and radius
		 * @param float $centerLat Center latitude coordinate
		 * @param float $centerLng Center longitude coordinate
		 * @param int $radiusMeters Radius in meters from center point
		 */
		public function setCircleGeometry(float $centerLat, float $centerLng, int $radiusMeters): void {
			$this->type = 'circle';
			
			$this->geometry = [
				'center' => [
					'lat' => $centerLat,
					'lng' => $centerLng
				],
				'radius' => $radiusMeters
			];
		}
		
		/**
		 * Get coordinate pairs for polygon fences
		 * @return array|null Array of [lat, lng] coordinate pairs or null if not a polygon
		 */
		public function getCoordinates(): ?array {
			if (!$this->isPolygon()) {
				return null;
			}
			
			return $this->geometry['coordinates'] ?? null;
		}
		
		/**
		 * Configure this fence as a polygon with coordinate points
		 * @param array $coordinates Array of [lat, lng] coordinate pairs defining the polygon boundary
		 */
		public function setPolygonGeometry(array $coordinates): void {
			$this->type = 'polygon';
			
			$this->geometry = [
				'coordinates' => $coordinates
			];
		}
		
		/**
		 * Check if the geo fence is currently active
		 * @return bool True if active, false if disabled
		 */
		public function isActive(): bool {
			return $this->active;
		}
		
		/**
		 * Enable or disable the geo fence
		 * @param bool $active True to activate, false to deactivate
		 */
		public function setActive(bool $active): void {
			$this->active = $active;
		}
		
		/**
		 * Get additional metadata associated with the fence
		 * @return array Associative array of custom metadata
		 */
		public function getMetadata(): array {
			return $this->metadata;
		}
		
		/**
		 * Set custom metadata for the fence
		 *
		 * @param array $metadata Associative array of custom data
		 */
		public function setMetadata(array $metadata): void {
			$this->metadata = $metadata;
		}
		
		/**
		 * Get the creation timestamp
		 * @return \DateTime When the fence was created
		 */
		public function getCreatedAt(): \DateTime {
			return $this->createdAt;
		}
		
		/**
		 * Set the creation timestamp
		 * @param \DateTime $createdAt Creation date and time
		 */
		public function setCreatedAt(\DateTime $createdAt): void {
			$this->createdAt = $createdAt;
		}
		
		/**
		 * Get the last update timestamp
		 * @return \DateTime When the fence was last modified
		 */
		public function getUpdatedAt(): \DateTime {
			return $this->updatedAt;
		}
		
		/**
		 * Set the last update timestamp
		 * @param \DateTime $updatedAt Last modification date and time
		 */
		public function setUpdatedAt(\DateTime $updatedAt): void {
			$this->updatedAt = $updatedAt;
		}
		
		/**
		 * Check if this fence is configured as a circle
		 * @return bool True if fence type is 'circle'
		 */
		public function isCircle(): bool {
			return $this->type === 'circle';
		}
		
		/**
		 * Check if this fence is configured as a polygon
		 * @return bool True if fence type is 'polygon'
		 */
		public function isPolygon(): bool {
			return $this->type === 'polygon';
		}
		
		/**
		 * Serialize to JSON
		 * @return array
		 */
		public function jsonSerialize(): array {
			$data = [
				'id'         => $this->id,
				'name'       => $this->name,
				'type'       => $this->type,
				'geometry'   => $this->geometry,
				'active'     => $this->active,
				'metadata'   => $this->metadata,
				'created_at' => $this->createdAt->format('c'),
				'updated_at' => $this->updatedAt->format('c')
			];
			
			// Add convenience properties for easier frontend consumption
			if ($this->isCircle()) {
				$data['center_lat'] = $this->getCenterLat();
				$data['center_lng'] = $this->getCenterLng();
				$data['radius_meters'] = $this->getRadiusMeters();
			}
			
			if ($this->isPolygon()) {
				$data['coordinates'] = $this->getCoordinates();
				$data['vertex_count'] = $this->getCoordinates() ? count($this->getCoordinates()) : 0;
			}
			
			return $data;
		}
	}