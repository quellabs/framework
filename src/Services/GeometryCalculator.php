<?php
	
	namespace App\Services;
	
	/**
	 * A service class for performing geometric calculations
	 * Primarily focused on geospatial operations using latitude/longitude coordinates
	 */
	class GeometryCalculator {
		
		/**
		 * Calculate distance between two points using Haversine formula
		 * The Haversine formula determines the great-circle distance between two points
		 * on a sphere given their latitude and longitude coordinates
		 * @param float $lat1 Latitude of first point in decimal degrees
		 * @param float $lng1 Longitude of first point in decimal degrees
		 * @param float $lat2 Latitude of second point in decimal degrees
		 * @param float $lng2 Longitude of second point in decimal degrees
		 * @return float Distance between the two points in meters
		 */
		public function calculateDistance(
			float $lat1,
			float $lng1,
			float $lat2,
			float $lng2
		): float {
			// Earth's radius in meters (mean radius)
			$earthRadius = 6371000; // meters
			
			// Convert latitude and longitude differences to radians
			$dLat = deg2rad($lat2 - $lat1);
			$dLng = deg2rad($lng2 - $lng1);
			
			// Haversine formula calculation
			// Calculate the square of half the chord length between the points
			$a =
				sin($dLat / 2) * sin($dLat / 2) +
				cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
				sin($dLng / 2) * sin($dLng / 2);
			
			// Calculate the angular distance in radians
			$c = 2 * atan2(sqrt($a), sqrt(1 - $a));
			
			// Calculate and return the distance in meters
			return $earthRadius * $c;
		}
		
		/**
		 * Check if point is inside polygon using ray casting algorithm
		 * Also known as the "point in polygon" test using the even-odd rule
		 * @param float $lat Latitude of the point to test
		 * @param float $lng Longitude of the point to test
		 * @param array $polygon Array of points defining the polygon, each containing 'lat' and 'lng' keys
		 * @return bool True if point is inside the polygon, false otherwise
		 */
		public function pointInPolygon(float $lat, float $lng, array $polygon): bool {
			// Use x,y coordinates for cleaner variable names in the algorithm
			$x = $lng;
			$y = $lat;
			$inside = false;
			
			// Ray casting algorithm: cast a ray from the point to infinity
			// and count how many times it intersects with polygon edges
			for ($i = 0, $j = count($polygon) - 1; $i < count($polygon); $j = $i++) {
				// Get the current edge vertices
				$vertex1 = $polygon[$i];
				$vertex2 = $polygon[$j];
				
				// Check if the edge crosses the horizontal ray from our point
				// First condition: edge must straddle the horizontal line through our point
				$edgeStraddlesRay = ($vertex1['lng'] > $x) != ($vertex2['lng'] > $x);
				
				if ($edgeStraddlesRay) {
					// Second condition: calculate where the edge intersects our horizontal ray
					// Using line intersection formula to find x-coordinate of intersection
					$intersectionX =
						($vertex2['lng'] - $vertex1['lng']) * ($y - $vertex1['lat']) /
						($vertex2['lat'] - $vertex1['lat']) + $vertex1['lng'];
					
					// If intersection is to the right of our point, the ray crosses this edge
					if ($x < $intersectionX) {
						$inside = !$inside;
					}
				}
			}
			
			// If crossed an odd number of edges, point is inside
			return $inside;
		}
		
		/**
		 * Get bounding box for a circle defined by center point and radius
		 * Returns the rectangular bounds that completely contain the circle
		 * @param float $centerLat Latitude of circle center in decimal degrees
		 * @param float $centerLng Longitude of circle center in decimal degrees
		 * @param int $radiusMeters Radius of the circle in meters
		 * @return array Associative array with 'north', 'south', 'east', 'west' bounds
		 */
		public function getCircleBounds(float $centerLat, float $centerLng, int $radiusMeters): array {
			// Earth's radius in meters (same as used in distance calculation)
			$earthRadius = 6371000;
			
			// Calculate latitude delta (same at all longitudes)
			$latDelta = rad2deg($radiusMeters / $earthRadius);
			
			// Calculate longitude delta (varies by latitude due to Earth's curvature)
			// Longitude lines converge toward the poles, so we need to adjust by cosine of latitude
			$lngDelta = rad2deg($radiusMeters / ($earthRadius * cos(deg2rad($centerLat))));
			
			// Return the bounding box coordinates
			return [
				'north' => $centerLat + $latDelta,  // Northernmost boundary
				'south' => $centerLat - $latDelta,  // Southernmost boundary
				'east'  => $centerLng + $lngDelta,  // Easternmost boundary
				'west'  => $centerLng - $lngDelta   // Westernmost boundary
			];
		}
	}