<?php
	
	namespace App\Validation;
	
	use Quellabs\Canvas\Validation\Contracts\ValidationInterface;
	use Quellabs\Canvas\Validation\Rules\NotBlank;
	use Quellabs\Canvas\Validation\Rules\Type;
	use Quellabs\Canvas\Validation\Rules\Length;
	use Quellabs\Canvas\Validation\Rules\ValueIn;
	use Quellabs\Canvas\Validation\Rules\RegExp;
	
	class GeoFenceValidation implements ValidationInterface {
		
		public function getRules(): array {
			return [
				'name'          => [
					new NotBlank('Fence name is required'),
					new Type('string', 'Fence name must be a string'),
					new Length(1, 255, 'Fence name must be between {{min}} and {{max}} characters')
				],
				'type'          => [
					new NotBlank('Fence type is required'),
					new ValueIn(['circle', 'polygon'], 'Fence type must be either "circle" or "polygon"')
				],
				// Circle-specific validation (only required when type is 'circle')
				'center_lat'    => [
					new Type('float', 'Center latitude must be a valid number'),
					new RegExp('/^-?([1-8]?\d(\.\d+)?|90(\.0+)?)$/', 'Center latitude must be between -90 and 90 degrees')
				],
				'center_lng'    => [
					new Type('float', 'Center longitude must be a valid number'),
					new RegExp('/^-?(180(\.0+)?|((1[0-7]\d)|([1-9]?\d))(\.\d+)?)$/', 'Center longitude must be between -180 and 180 degrees')
				],
				'radius_meters' => [
					new Type('integer', 'Radius must be a valid integer'),
					new RegExp('/^[1-9]\d*$/', 'Radius must be a positive integer greater than 0')
				],
				// Polygon-specific validation (only required when type is 'polygon')
				'coordinates'   => [
					new Type('array', 'Coordinates must be an array')
				],
				'metadata'      => [
					new Type('array', 'Metadata must be an array')
				]
			];
		}
	}