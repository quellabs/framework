<?php
	
	namespace Quellabs\Shipments\PostNL\Transformers;
	
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\Contracts\ShipmentInitiationException;
	use Quellabs\Shipments\Contracts\ShipmentResult;
	use Quellabs\Shipments\PostNL\Driver;
	
	class ShipmentResultTransformer {
		
		use GatewayHelpers;
		
		/**
		 * Maps a successful PostNL shipment creation response to a ShipmentResult.
		 *
		 * Expects $response to be $result['response'] from PostNLGateway::createShipment(),
		 * after the caller has already verified $result['request']['result'] !== 0.
		 *
		 * @param array<string, mixed> $response Raw PostNL creation response body.
		 * @param string $reference Original ShipmentRequest::$reference.
		 * @param string $postalCode Recipient postal code for tracking URL construction.
		 * @return ShipmentResult
		 * @throws ShipmentInitiationException
		 */
		public function transform(array $response, string $reference, string $postalCode): ShipmentResult {
			// Fetch the shipment data
			$responseShipments = $this->arrayGetArray($response, 'ResponseShipments');
			$responseShipment = (is_array($responseShipments[0] ?? null)) ? $responseShipments[0] : null;
			
			// If that failed, throw an error
			if ($responseShipment === null) {
				throw new ShipmentInitiationException(
					Driver::DRIVER_NAME,
					'missing_shipment',
					'PostNL did not return a shipment in the creation response'
				);
			}
			
			// Fetch the barcode
			$barcode = $this->arrayGetString($responseShipment, 'Barcode');
			
			// If that failed, throw an error
			if (empty($barcode)) {
				throw new ShipmentInitiationException(
					Driver::DRIVER_NAME,
					'missing_barcode',
					'PostNL did not return a barcode in the creation response'
				);
			}
			
			// Return result — call getLabelUrl() explicitly to fetch the label
			return new ShipmentResult(
				provider: Driver::DRIVER_NAME,
				parcelId: $barcode,
				reference: $reference,
				trackingCode: $barcode,
				trackingUrl: PostNLUrlBuilder::trackingUrl($barcode, $postalCode),
				carrierName: 'PostNL',
				rawResponse: $response,
			);
		}
	}