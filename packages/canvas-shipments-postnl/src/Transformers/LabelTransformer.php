<?php
	
	namespace Quellabs\Shipments\PostNL\Transformers;
	
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\Contracts\ShipmentLabelException;
	use Quellabs\Shipments\PostNL\Driver;
	
	class LabelTransformer {
		
		use GatewayHelpers;
		
		/**
		 * Maps a successful PostNL label response to a base64 data URI.
		 *
		 * Expects $response to be $result['response'] from PostNLGateway::getLabel(),
		 * after the caller has already verified $result['request']['result'] !== 0.
		 *
		 * Returns a data URI (data:application/pdf;base64,...) ready for decoding or storage.
		 *
		 * @param array<string, mixed> $response Raw PostNL label response body.
		 * @param string $parcelId The PostNL barcode, used in error messages.
		 * @return string
		 * @throws ShipmentLabelException
		 */
		public function transform(array $response, string $parcelId): string {
			// Fetch the label content
			$responseShipments = $this->arrayGetArray($response, 'ResponseShipments');
			$firstShipment = (is_array($responseShipments[0] ?? null)) ? $responseShipments[0] : [];
			$labels = $this->arrayGetArray($firstShipment, 'Labels');
			$firstLabel = (is_array($labels[0] ?? null)) ? $labels[0] : [];
			$labelContent = $this->arrayGetString($firstLabel, 'Content');
			
			// If there's none, throw error
			if ($labelContent === null) {
				throw new ShipmentLabelException(
					Driver::DRIVER_NAME,
					'missing_label',
					"No label content returned for barcode {$parcelId}"
				);
			}
			
			// Return label content as base64 encoded data
			return 'data:application/pdf;base64,' . $labelContent;
		}
	}