<?php
	
	namespace Quellabs\Shipments\PostNL;
	
	use Quellabs\Shipments\Contracts\CancelRequest;
	use Quellabs\Shipments\Contracts\CancelResult;
	use Quellabs\Shipments\Contracts\DeliveryOption;
	use Quellabs\Shipments\Contracts\PickupOption;
	use Quellabs\Shipments\Contracts\ShipmentAddress;
	use Quellabs\Shipments\Contracts\ShipmentCancellationException;
	use Quellabs\Shipments\Contracts\ShipmentCreationException;
	use Quellabs\Shipments\Contracts\ShipmentExchangeException;
	use Quellabs\Shipments\Contracts\ShipmentLabelException;
	use Quellabs\Shipments\Contracts\ShipmentProviderInterface;
	use Quellabs\Shipments\Contracts\ShipmentRequest;
	use Quellabs\Shipments\Contracts\ShipmentResult;
	use Quellabs\Shipments\Contracts\ShipmentState;
	use Quellabs\Shipments\Contracts\ShipmentStatus;
	
	class Driver implements ShipmentProviderInterface {
		
		/**
		 * Driver name — stored in ShipmentResult::$provider and ShipmentState::$provider.
		 * Used by ShipmentRouter::exchange() to re-resolve this driver later.
		 */
		const DRIVER_NAME = 'postnl';
		
		/**
		 * Active configuration, applied by the discovery system after instantiation.
		 * @var array
		 */
		private array $config = [];
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var PostNLGateway|null
		 */
		private ?PostNLGateway $gateway = null;
		
		/**
		 * Maps our internal module names to PostNL product codes.
		 *
		 * Each product code must be agreed upon in your PostNL Pakketten contract before use.
		 * Codes are grouped below by service category.
		 *
		 * --- Domestic NL — standard ---
		 *   3085 = Standard shipment, home delivery
		 *   3087 = Standard shipment, signature on delivery
		 *   3090 = Standard shipment, delivery to stated address only
		 *   3094 = Standard shipment, age check 18+ on delivery
		 *   3096 = Standard shipment, Sunday/holiday delivery
		 *   3189 = Standard shipment, ID check on delivery
		 *   3385 = Standard shipment, same-day / Sunday delivery
		 *   3390 = Standard shipment, same-day + delivery to stated address only
		 *
		 * --- Domestic NL — with insurance (COD/extra cover) ---
		 *   3086 = Standard shipment with extra cover / COD
		 *   3091 = Signature + extra cover
		 *   3093 = Signature + age check 18+ + extra cover
		 *   3097 = Sunday/holiday + extra cover
		 *   3389 = Same-day + extra cover
		 *
		 * --- Domestic NL — Extra@Home (large/heavy parcels) ---
		 *   3089 = Extra@Home standard
		 *   3389 = Extra@Home same-day (maps to same-day + extra cover above; distinct service)
		 *
		 * --- Domestic NL — mailbox parcel ---
		 *   2928 = Mailbox parcel (brievenbuspakje)
		 *
		 * --- Domestic NL — pick-up at PostNL location ---
		 *   3533 = Pickup point, standard
		 *   3534 = Pickup point, with extra cover
		 *   3535 = Pickup point, COD
		 *   3536 = Pickup point, COD + extra cover
		 *   3543 = Pickup point, signature
		 *   3544 = Pickup point, signature + extra cover
		 *   3545 = Pickup point, signature + COD
		 *   3546 = Pickup point, signature + COD + extra cover
		 *   3571 = Pickup point, standard (consumer-facing product)
		 *   3572 = Pickup point, signature (consumer-facing)
		 *   3573 = Pickup point, ID check
		 *   3574 = Pickup point, age check 18+
		 *   3575 = Pickup point, extra cover (consumer-facing)
		 *   3576 = Pickup point, extra cover + signature (consumer-facing)
		 *
		 * --- Domestic NL — age & ID check variants ---
		 *   3437 = Age check 18+, home delivery
		 *   3438 = Age check 18+, home delivery + extra cover
		 *   3440 = ID check, home delivery
		 *   3442 = ID check + age check 18+, home delivery
		 *   3444 = ID check, pickup point
		 *   3446 = ID check + age check 18+, pickup point
		 *
		 * --- Domestic NL — returns ---
		 *   2828 = Return label (label-in-the-box / smart return)
		 *   4910 = ERS return label (international returns, same rules as 3085)
		 *
		 * --- NL → Belgium ---
		 *   4946 = Standard shipment NL → BE
		 *   4912 = Standard shipment NL → BE, signature
		 *   4914 = Standard shipment NL → BE, extra cover
		 *   4941 = Standard shipment NL → BE, age check 18+
		 *
		 * --- Domestic Belgium (BE → BE) ---
		 *   4960 = Standard domestic BE
		 *   4961 = Standard domestic BE, signature
		 *   4962 = Standard domestic BE, extra cover
		 *   4963 = Standard domestic BE, age check 18+
		 *   4965 = Standard domestic BE, ID check
		 *
		 * --- BE — pick-up at PostNL location ---
		 *   4878 = Pickup point BE, standard
		 *   4880 = Pickup point BE, COD
		 *
		 * --- EU (EPS) ---
		 *   4907 = EU Pack Special (EPS), standard — replaces legacy 4940/4944/4950/4954
		 *   4909 = GlobalPack, standard — replaces legacy 4945/4947
		 *   4936 = EU Pack Special BE → EU
		 *   4952 = EU Pack Special (combilabel, consumer)
		 *   4999 = EU Pack Special, documents
		 *
		 * --- GlobalPack (world outside EU) ---
		 *   4909 = GlobalPack standard (see EU note above)
		 *
		 * --- Miscellaneous ---
		 *   6350 = Parcel dispenser / locker delivery
		 *
		 * @see https://developer.postnl.nl/browse-apis/send-and-track/products/
		 */
		private const MODULE_PRODUCT_MAP = [
			// Domestic NL — standard home delivery
			'postnl_standard'                          => ['productCode' => '3085'],
			'postnl_signature'                         => ['productCode' => '3087'],
			'postnl_stated_address'                    => ['productCode' => '3090'],
			'postnl_age_check'                         => ['productCode' => '3094'],
			'postnl_sunday'                            => ['productCode' => '3096'],
			'postnl_id_check'                          => ['productCode' => '3189'],
			'postnl_sameday'                           => ['productCode' => '3385'],
			'postnl_sameday_stated_address'            => ['productCode' => '3390'],
			
			// Domestic NL — with insurance / COD
			'postnl_insured'                           => ['productCode' => '3086'],
			'postnl_signature_insured'                 => ['productCode' => '3091'],
			'postnl_signature_age_insured'             => ['productCode' => '3093'],
			'postnl_sunday_insured'                    => ['productCode' => '3097'],
			'postnl_sameday_insured'                   => ['productCode' => '3389'],
			
			// Domestic NL — Extra@Home (large/heavy parcels)
			'postnl_extrahome'                         => ['productCode' => '3089'],
			
			// Domestic NL — mailbox parcel
			'postnl_mailbox'                           => ['productCode' => '2928'],
			
			// Domestic NL — age & ID check variants
			'postnl_age_check_home'                    => ['productCode' => '3437'],
			'postnl_age_check_home_insured'            => ['productCode' => '3438'],
			'postnl_id_check_home'                     => ['productCode' => '3440'],
			'postnl_id_age_check_home'                 => ['productCode' => '3442'],
			'postnl_id_check_pickup'                   => ['productCode' => '3444'],
			'postnl_id_age_check_pickup'               => ['productCode' => '3446'],
			
			// Domestic NL — pick-up at PostNL location
			'postnl_pickup'                            => ['productCode' => '3533'],
			'postnl_pickup_insured'                    => ['productCode' => '3534'],
			'postnl_pickup_cod'                        => ['productCode' => '3535'],
			'postnl_pickup_cod_insured'                => ['productCode' => '3536'],
			'postnl_pickup_signature'                  => ['productCode' => '3543'],
			'postnl_pickup_signature_insured'          => ['productCode' => '3544'],
			'postnl_pickup_signature_cod'              => ['productCode' => '3545'],
			'postnl_pickup_signature_cod_insured'      => ['productCode' => '3546'],
			'postnl_pickup_consumer'                   => ['productCode' => '3571'],
			'postnl_pickup_consumer_signature'         => ['productCode' => '3572'],
			'postnl_pickup_consumer_id'                => ['productCode' => '3573'],
			'postnl_pickup_consumer_age'               => ['productCode' => '3574'],
			'postnl_pickup_consumer_insured'           => ['productCode' => '3575'],
			'postnl_pickup_consumer_insured_signature' => ['productCode' => '3576'],
			
			// Domestic NL — returns
			'postnl_return'                            => ['productCode' => '2828'],
			'postnl_return_international'              => ['productCode' => '4910'],
			
			// NL → Belgium
			'postnl_be_standard'                       => ['productCode' => '4946'],
			'postnl_be_signature'                      => ['productCode' => '4912'],
			'postnl_be_insured'                        => ['productCode' => '4914'],
			'postnl_be_age_check'                      => ['productCode' => '4941'],
			
			// Domestic Belgium (BE → BE)
			'postnl_be_domestic'                       => ['productCode' => '4960'],
			'postnl_be_domestic_signature'             => ['productCode' => '4961'],
			'postnl_be_domestic_insured'               => ['productCode' => '4962'],
			'postnl_be_domestic_age_check'             => ['productCode' => '4963'],
			'postnl_be_domestic_id_check'              => ['productCode' => '4965'],
			
			// Belgium — pick-up at PostNL location
			'postnl_be_pickup'                         => ['productCode' => '4878'],
			'postnl_be_pickup_cod'                     => ['productCode' => '4880'],
			
			// EU (EPS / EU Pack Special)
			'postnl_eu'                                => ['productCode' => '4907'],
			'postnl_eu_be'                             => ['productCode' => '4936'],
			'postnl_eu_consumer'                       => ['productCode' => '4952'],
			'postnl_eu_documents'                      => ['productCode' => '4999'],
			
			// GlobalPack (world outside EU)
			'postnl_global'                            => ['productCode' => '4909'],
			
			// Miscellaneous
			'postnl_locker'                            => ['productCode' => '6350'],
		];
		
		/**
		 * Maps PostNL status codes to normalised ShipmentStatus values.
		 *
		 * PostNL returns a numeric status code and a description via the Status API.
		 * Phase codes indicate the broad lifecycle stage; status codes refine it.
		 *
		 * Phase codes:
		 *   1 = Collected
		 *   2 = Sorting
		 *   3 = Distribution
		 *   4 = Delivered
		 *   99 = Return to sender
		 *
		 * @see https://developer.postnl.nl/docs/#/http/api-endpoints/status/status-by-barcode
		 */
		private const STATUS_MAP = [
			// Phase 1 — accepted by PostNL
			1  => ShipmentStatus::ReadyToSend,
			
			// Phase 2 — processing at sorting centre
			2  => ShipmentStatus::InTransit,
			
			// Phase 3 — in distribution network
			3  => ShipmentStatus::InTransit,
			
			// Phase 4 — final delivery outcomes
			4  => ShipmentStatus::Delivered,
			11 => ShipmentStatus::DeliveryFailed,
			12 => ShipmentStatus::AwaitingPickup,
			13 => ShipmentStatus::Delivered,       // Delivered at neighbour
			14 => ShipmentStatus::Delivered,       // Delivered in mailbox
			
			// Phase 99 — return
			99 => ShipmentStatus::ReturnedToSender,
		];
		
		/**
		 * Returns discovery metadata for this provider.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'driver'  => self::DRIVER_NAME,
				'modules' => array_keys(self::MODULE_PRODUCT_MAP),
			];
		}
		
		/**
		 * Returns the active configuration for this driver instance.
		 * @return array
		 */
		public function getConfig(): array {
			return array_replace_recursive($this->getDefaults(), $this->config);
		}
		
		/**
		 * Applies configuration to this driver instance.
		 * Called by the discovery system after instantiation, before any other methods.
		 * @param array $config
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = $config;
		}
		
		/**
		 * Returns default configuration values for this driver.
		 * @return array
		 */
		public function getDefaults(): array {
			return [
				'api_key'             => '',
				'api_key_test'        => '',
				'test_mode'           => false,
				'customer_code'       => '',
				'customer_number'     => '',
				'collection_location' => '',
				'sender_address'      => [],
			];
		}
		
		/**
		 * Creates a parcel via the PostNL Shipment API and returns a structured result.
		 *
		 * The PostNL API returns the barcode and label inline in the creation response,
		 * so no second call is needed. The label is returned as a base64-encoded PDF;
		 * the gateway decodes it and stores it, returning a data URI or a URL depending
		 * on the configured label format.
		 *
		 * @param ShipmentRequest $request
		 * @return ShipmentResult
		 * @throws ShipmentCreationException
		 */
		public function create(ShipmentRequest $request): ShipmentResult {
			$productInfo = self::MODULE_PRODUCT_MAP[$request->shippingModule] ?? null;
			
			if ($productInfo === null) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'unknown_module',
					"Unknown shipping module '{$request->shippingModule}'"
				);
			}
			
			$config = $this->getConfig();
			
			$shipmentPayload = [
				'Customer'  => [
					'Address'            => array_filter([
						'AddressType' => '02',
						'City'        => $config['sender_address']['city'] ?? '',
						'CompanyName' => $config['sender_address']['company'] ?? '',
						'Countrycode' => $config['sender_address']['country'] ?? 'NL',
						'HouseNr'     => $config['sender_address']['houseNumber'] ?? '',
						'Street'      => $config['sender_address']['street'] ?? '',
						'Zipcode'     => $config['sender_address']['postalCode'] ?? '',
					], fn($v) => $v !== ''),
					'CollectionLocation' => $config['collection_location'],
					'CustomerCode'       => $config['customer_code'],
					'CustomerNumber'     => $config['customer_number'],
				],
				'Message'   => [
					'MessageID'        => (string)time(),
					'MessageTimeStamp' => (new \DateTimeImmutable())->format('d-m-Y H:i:s'),
					'Printertype'      => 'GraphicFile|PDF',
				],
				'Shipments' => [
					array_filter([
						'Addresses'           => [
							[
								'AddressType' => '01',
								'City'        => $request->deliveryAddress->city,
								'CompanyName' => $request->deliveryAddress->company ?? '',
								'Countrycode' => $request->deliveryAddress->country,
								'HouseNr'     => $request->deliveryAddress->houseNumber,
								'HouseNrExt'  => $request->deliveryAddress->houseNumberSuffix ?? '',
								'Name'        => $request->deliveryAddress->name,
								'Street'      => $request->deliveryAddress->street,
								'Zipcode'     => $request->deliveryAddress->postalCode,
							],
						],
						'Contacts'            => array_filter([
							$request->deliveryAddress->email !== null || $request->deliveryAddress->phone !== null
								? array_filter([
								'ContactType' => '01',
								'Email'       => $request->deliveryAddress->email,
								'TelNr'       => $request->deliveryAddress->phone,
							], fn($v) => $v !== null)
								: null,
						]),
						'DeliveryAddress'     => $request->servicePointId !== null ? '09' : '01',
						'DeliveryDate'        => (new \DateTimeImmutable('+1 weekday'))->format('d-m-Y'),
						'Dimension'           => [
							'Weight' => $request->weightGrams,
						],
						'ProductCodeDelivery' => $productInfo['productCode'],
						'Reference'           => $request->reference,
						'ServicePointID'      => $request->servicePointId,
					], fn($v) => $v !== null && $v !== '' && $v !== []),
				],
			];
			
			if (!empty($request->extraData)) {
				$shipmentPayload = array_merge_recursive($shipmentPayload, $request->extraData);
			}
			
			$result = $this->getGateway()->createShipment($shipmentPayload);
			
			if ($result['request']['result'] === 0) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			$responseShipment = $result['response']['ResponseShipments'][0] ?? null;
			
			if ($responseShipment === null) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'missing_shipment',
					'PostNL did not return a shipment in the creation response'
				);
			}
			
			$barcode = $responseShipment['Barcode'] ?? null;
			
			if (empty($barcode)) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'missing_barcode',
					'PostNL did not return a barcode in the creation response'
				);
			}
			
			// Label is returned inline as base64-encoded PDF content
			$labelUrl = null;
			
			if ($request->requestLabel) {
				$labelContent = $responseShipment['Labels'][0]['Content'] ?? null;
				
				if ($labelContent !== null) {
					// Store decoded label bytes as a data URI; callers needing a file
					// should decode and write it themselves, or use getLabelUrl() for a
					// server-side URL after persisting the PDF to storage.
					$labelUrl = 'data:application/pdf;base64,' . $labelContent;
				}
			}
			
			$postalCode = $request->deliveryAddress->postalCode;
			
			return new ShipmentResult(
				provider: self::DRIVER_NAME,
				parcelId: $barcode,
				reference: $request->reference,
				trackingCode: $barcode,
				trackingUrl: $this->buildTrackingUrl($barcode, $postalCode),
				labelUrl: $labelUrl,
				carrierName: 'PostNL',
				rawResponse: $result['response'],
			);
		}
		
		/**
		 * Cancels a previously created shipment via the PostNL Shipment API.
		 *
		 * PostNL supports programmatic cancellation only while the barcode has not yet
		 * been scanned by the carrier. Once the parcel is in transit, cancellation must
		 * be arranged through PostNL customer service.
		 *
		 * @param CancelRequest $request
		 * @return CancelResult
		 * @throws ShipmentCancellationException
		 */
		public function cancel(CancelRequest $request): CancelResult {
			$result = $this->getGateway()->deleteShipment($request->parcelId);
			
			if ($result['request']['result'] === 0) {
				// Distinguish "already in transit" (409 Conflict) from hard failures
				$errorId = $result['request']['errorId'];
				
				if ($errorId === 409) {
					return new CancelResult(
						provider: self::DRIVER_NAME,
						parcelId: $request->parcelId,
						reference: $request->reference,
						accepted: false,
						message: 'Parcel already accepted by the carrier and cannot be cancelled programmatically.',
					);
				}
				
				throw new ShipmentCancellationException(
					self::DRIVER_NAME,
					$errorId,
					$result['request']['errorMessage']
				);
			}
			
			return new CancelResult(
				provider: self::DRIVER_NAME,
				parcelId: $request->parcelId,
				reference: $request->reference,
				accepted: true,
			);
		}
		
		/**
		 * Fetches the current state of a parcel from the PostNL Status API.
		 *
		 * PostNL's Status API uses the carrier barcode as its primary identifier.
		 * The parcelId stored in ShipmentResult is the barcode, so this maps directly.
		 *
		 * @param string $parcelId The PostNL barcode from ShipmentResult::$parcelId
		 * @return ShipmentState
		 * @throws ShipmentExchangeException
		 */
		public function exchange(string $parcelId): ShipmentState {
			$result = $this->getGateway()->getStatus($parcelId);
			
			if ($result['request']['result'] === 0) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			$shipmentData = $result['response']['Shipment'] ?? null;
			
			if ($shipmentData === null) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					'not_found',
					"No shipment data returned for barcode {$parcelId}"
				);
			}
			
			return $this->buildStateFromShipment($shipmentData);
		}
		
		/**
		 * Returns normalised home delivery options for the given module.
		 *
		 * Calls the PostNL Timeframe API, which returns all available delivery windows
		 * (Daytime, Morning, Evening, Sunday) per day over a 5-day window from tomorrow.
		 * Each slot becomes one DeliveryOption; the caller gets a full list suitable for
		 * rendering a checkout delivery picker.
		 *
		 * The methodId encodes the delivery date, window, and option type so the caller
		 * can pass it back in ShipmentRequest::$methodId and the driver can reconstruct
		 * what product option code is needed at shipment creation time.
		 * Format: 'dd-mm-yyyy|HH:MM:SS|HH:MM:SS|OptionType'
		 * Example: '08-04-2026|08:00:00|12:00:00|Morning'
		 *
		 * Pickup point and locker modules return an empty array — use getPickupOptions() instead.
		 * Returns an empty array if no address is provided.
		 *
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return DeliveryOption[]
		 */
		public function getDeliveryOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			$productInfo = self::MODULE_PRODUCT_MAP[$shippingModule] ?? null;
			
			if ($productInfo === null || $this->isPickupModule($productInfo['productCode'])) {
				return [];
			}
			
			if ($address === null) {
				return [];
			}
			
			$startDate = (new \DateTimeImmutable('+1 day'))->format('d-m-Y');
			$endDate = (new \DateTimeImmutable('+6 days'))->format('d-m-Y');
			
			$result = $this->getGateway()->getTimeframes(
				$address->postalCode,
				$address->houseNumber,
				$address->country,
				$startDate,
				$endDate,
				['Daytime', 'Morning', 'Evening', 'Sunday'],
			);
			
			if ($result['request']['result'] === 0) {
				return [];
			}
			
			// The Timeframe API wraps its result in Timeframes.Timeframe[]
			$days = $result['response']['Timeframes']['Timeframe'] ?? [];
			
			// When the API returns a single day it may not wrap it in an array
			if (isset($days['Date'])) {
				$days = [$days];
			}
			
			$options = [];
			
			foreach ($days as $day) {
				$dateStr = $day['Date'] ?? null;
				
				if ($dateStr === null) {
					continue;
				}
				
				$date = \DateTimeImmutable::createFromFormat('d-m-Y', $dateStr) ?: null;
				
				// Slots are in TimeframeTimeFrame[], same single-item wrapping caveat applies
				$slots = $day['Timeframes']['TimeframeTimeFrame'] ?? [];
				
				if (isset($slots['From'])) {
					$slots = [$slots];
				}
				
				foreach ($slots as $slot) {
					$from = $slot['From'] ?? '';   // e.g. '08:00:00'
					$to = $slot['To'] ?? '';     // e.g. '12:00:00'
					
					// Options is either a string or { "string": "Morning" }
					$optionType = $slot['Options']['string'] ?? $slot['Options'] ?? 'Daytime';
					
					$windowStart = substr($from, 0, 5); // '08:00:00' → '08:00'
					$windowEnd = substr($to, 0, 5);
					
					$options[] = new DeliveryOption(
						methodId: "{$dateStr}|{$from}|{$to}|{$optionType}",
						label: $this->buildDeliveryLabel($date, $windowStart, $windowEnd, $optionType),
						carrierName: 'PostNL',
						deliveryDate: $date,
						windowStart: $windowStart ?: null,
						windowEnd: $windowEnd ?: null,
						metadata: [
							'optionType'  => $optionType,
							'productCode' => $productInfo['productCode'],
						],
					);
				}
			}
			
			return $options;
		}
		
		/**
		 * Builds a human-readable label for a delivery timeframe slot.
		 * @param \DateTimeImmutable|null $date
		 * @param string $windowStart e.g. '08:00'
		 * @param string $windowEnd e.g. '12:00'
		 * @param string $optionType e.g. 'Morning', 'Evening', 'Daytime', 'Sunday'
		 * @return string
		 */
		private function buildDeliveryLabel(?\DateTimeImmutable $date, string $windowStart, string $windowEnd, string $optionType): string {
			$dateStr = $date ? $date->format('d-m-Y') : '';
			$window = ($windowStart !== '' && $windowEnd !== '') ? " {$windowStart}–{$windowEnd}" : '';
			
			return match ($optionType) {
				'Morning' => trim("{$dateStr} Morning{$window}"),
				'Evening' => trim("{$dateStr} Evening{$window}"),
				'Sunday' => trim("{$dateStr} Sunday{$window}"),
				default => trim("{$dateStr}{$window}"),
			};
		}
		
		/**
		 * Returns normalised pickup point options near the given address.
		 *
		 * Calls the PostNL Location API to find the nearest service points.
		 * Returns an empty array if no address is provided.
		 *
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return PickupOption[]
		 */
		public function getPickupOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			if ($address === null) {
				return [];
			}
			
			$result = $this->getGateway()->getNearestLocations(
				$address->postalCode,
				$address->houseNumber,
				$address->country,
			);
			
			if ($result['request']['result'] === 0) {
				return [];
			}
			
			$locations = $result['response']['GetLocationsResult']['ResponseLocation'] ?? [];
			$options = [];
			
			foreach ($locations as $location) {
				$address_ = $location['Address'] ?? [];
				
				$options[] = new PickupOption(
					locationCode: (string)($location['LocationCode'] ?? ''),
					name: $location['Name'] ?? '',
					street: $address_['Street'] ?? '',
					houseNumber: (string)($address_['HouseNr'] ?? ''),
					postalCode: $address_['Zipcode'] ?? '',
					city: $address_['City'] ?? '',
					country: $address_['Countrycode'] ?? '',
					carrierName: 'PostNL',
					latitude: isset($location['Latitude']) ? (float)$location['Latitude'] : null,
					longitude: isset($location['Longitude']) ? (float)$location['Longitude'] : null,
					distanceMetres: isset($location['Distance']) ? (int)$location['Distance'] : null,
					metadata: array_filter([
						'retailNetworkId' => $location['RetailNetworkID'] ?? null,
						'partnerName'     => $location['PartnerName'] ?? null,
						'openingHours'    => $location['OpeningHours'] ?? null,
						'phoneNumber'     => $location['PhoneNumber'] ?? null,
						'deliveryOptions' => $location['DeliveryOptions'] ?? null,
					], fn($v) => $v !== null),
				);
			}
			
			return $options;
		}
		
		/**
		 * Returns the label URL for a given parcel barcode.
		 *
		 * Labels are returned inline at creation time. If a stored URL is not available,
		 * re-request the label via the Confirming/Label endpoint using the barcode.
		 *
		 * @param string $parcelId The PostNL barcode from ShipmentResult::$parcelId
		 * @return string
		 * @throws ShipmentLabelException
		 */
		public function getLabelUrl(string $parcelId): string {
			$result = $this->getGateway()->getLabel($parcelId);
			
			if ($result['request']['result'] === 0) {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			$labelContent = $result['response']['ResponseShipments'][0]['Labels'][0]['Content'] ?? null;
			
			if ($labelContent === null) {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					'missing_label',
					"No label content returned for barcode {$parcelId}"
				);
			}
			
			return 'data:application/pdf;base64,' . $labelContent;
		}
		
		/**
		 * Builds a ShipmentState from a raw shipment array returned by the PostNL Status API.
		 * Used by exchange() and the webhook controller.
		 * @param array $shipment The Shipment object from the PostNL Status API response
		 * @return ShipmentState
		 */
		public function buildStateFromShipment(array $shipment): ShipmentState {
			// PostNL returns status events ordered newest-first; the first entry is current state
			$currentEvent = $shipment['Events'][0] ?? [];
			$phaseCode = (int)($currentEvent['PhaseCode'] ?? 0);
			$statusCode = (int)($currentEvent['StatusCode'] ?? 0);
			$statusDescription = $currentEvent['Description'] ?? null;
			
			// StatusCode takes precedence for fine-grained mapping; fall back to PhaseCode
			$status = self::STATUS_MAP[$statusCode] ?? self::STATUS_MAP[$phaseCode] ?? ShipmentStatus::Unknown;
			$internalState = $phaseCode . '.' . $statusCode;
			$barcode = $shipment['Barcode'] ?? '';
			$postalCode = $shipment['Addresses'][0]['Zipcode'] ?? '';
			$reference = $shipment['Reference'] ?? '';
			
			return new ShipmentState(
				provider: self::DRIVER_NAME,
				parcelId: $barcode,
				reference: $reference,
				state: $status,
				trackingCode: $barcode,
				trackingUrl: $this->buildTrackingUrl($barcode, $postalCode),
				statusMessage: $statusDescription,
				internalState: $internalState,
				metadata: array_filter([
					'phaseCode'   => $phaseCode ?: null,
					'statusCode'  => $statusCode ?: null,
					'postalCode'  => $postalCode ?: null,
					'productCode' => $shipment['ProductCode'] ?? null,
				], fn($v) => $v !== null),
			);
		}
		
		/**
		 * Lazily instantiates and returns the PostNL gateway.
		 * @return PostNLGateway
		 */
		private function getGateway(): PostNLGateway {
			return $this->gateway ??= new PostNLGateway($this);
		}
		
		/**
		 * Returns true when the given product code represents a pickup point or locker service.
		 * These modules have no home delivery date and must be excluded from getDeliveryOptions().
		 *
		 * Pickup product code ranges per PostNL documentation:
		 *   3533–3546  — standard pickup point variants (NL)
		 *   3571–3576  — consumer pickup point variants (NL)
		 *   4878, 4880 — pickup point variants (BE)
		 *   6350       — parcel locker
		 *
		 * @param string $productCode
		 * @return bool
		 */
		private function isPickupModule(string $productCode): bool {
			$code = (int)$productCode;
			
			return ($code >= 3533 && $code <= 3546)
				|| ($code >= 3571 && $code <= 3576)
				|| $code === 4878
				|| $code === 4880
				|| $code === 6350;
		}
		
		/**
		 * Constructs the public PostNL track-and-trace URL for a barcode.
		 * @param string $barcode
		 * @param string $postalCode
		 * @return string
		 */
		private function buildTrackingUrl(string $barcode, string $postalCode): string {
			return 'https://postnl.nl/tracktrace/?B=' . rawurlencode($barcode)
				. '&P=' . rawurlencode($postalCode)
				. '&D=NL&T=C';
		}
	}