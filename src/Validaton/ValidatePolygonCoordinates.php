<?php
	
	namespace Quellabs\GeoFencing\Validation\Rules;
	
	class ValidatePolygonCoordinates {
		
		private int $minVertices;
		
		public function __construct(int $minVertices = 3) {
			$this->minVertices = $minVertices;
		}
		
		public function validate($value, array $options = []): bool {
			// Must be an array
			if (!is_array($value)) {
				return false;
			}
			
			// Must have minimum number of vertices (typically 3 for triangle)
			if (count($value) < $this->minVertices) {
				return false;
			}
			
			// Each coordinate must be a valid lat/lng pair
			foreach ($value as $coordinate) {
				if (!is_array($coordinate)) {
					return false;
				}
				
				// Must have lat and lng keys
				if (!isset($coordinate['lat']) || !isset($coordinate['lng'])) {
					return false;
				}
				
				$lat = $coordinate['lat'];
				$lng = $coordinate['lng'];
				
				// Validate latitude range (-90 to 90)
				if (!is_numeric($lat) || $lat < -90 || $lat > 90) {
					return false;
				}
				
				// Validate longitude range (-180 to 180)
				if (!is_numeric($lng) || $lng < -180 || $lng > 180) {
					return false;
				}
			}
			
			return true;
		}
		
		public function getMessage(): string {
			return "Coordinates must be an array of at least {$this->minVertices} valid lat/lng pairs";
		}
	}