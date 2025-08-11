<?php
	
	namespace App\Validation;
	
	use Quellabs\Canvas\Validation\Contracts\ValidationInterface;
	use Quellabs\Canvas\Validation\Rules\NotBlank;
	use Quellabs\Canvas\Validation\Rules\Type;
	use Quellabs\Canvas\Validation\Rules\RegExp;
	
	class LocationValidation implements ValidationInterface {
		
		public function getRules(): array {
			return [
				'latitude'  => [
					new NotBlank('Latitude is required'),
					new Type('float', 'Latitude must be a valid number'),
					new RegExp('/^-?([1-8]?\d(\.\d+)?|90(\.0+)?)$/', 'Latitude must be between -90 and 90 degrees')
				],
				'longitude' => [
					new NotBlank('Longitude is required'),
					new Type('float', 'Longitude must be a valid number'),
					new RegExp('/^-?(180(\.0+)?|((1[0-7]\d)|([1-9]?\d))(\.\d+)?)$/', 'Longitude must be between -180 and 180 degrees')
				],
				'accuracy'  => [
					new Type('float', 'Accuracy must be a valid number'),
					new RegExp('/^[0-9]+(\.[0-9]+)?$/', 'Accuracy must be a positive number')
				],
				'altitude'  => [
					new Type('float', 'Altitude must be a valid number')
					// Altitude can be negative (below sea level), so no range restriction
				],
				'heading'   => [
					new Type('float', 'Heading must be a valid number'),
					new RegExp('/^(360(\.0+)?|([0-2]?\d{1,2}|3[0-5]\d)(\.\d+)?)$/', 'Heading must be between 0 and 360 degrees')
				],
				'speed'     => [
					new Type('float', 'Speed must be a valid number'),
					new RegExp('/^[0-9]+(\.[0-9]+)?$/', 'Speed must be a positive number')
				],
				'timestamp' => [
					// Optional: validate ISO 8601 format if provided
					new RegExp('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?([+-]\d{2}:\d{2}|Z)?$/', 'Timestamp must be in ISO 8601 format')
				]
			];
		}
	}