<?php
	
	namespace Quellabs\Shipments\PostNL;
	
	use Quellabs\Contracts\Gateway\GatewayHelpers;
	use Quellabs\Shipments\Contracts\CancelRequest;
	use Quellabs\Shipments\Contracts\CancelResult;
	use Quellabs\Shipments\Contracts\DeliveryOption;
	use Quellabs\Shipments\Contracts\PickupOption;
	use Quellabs\Shipments\Contracts\ShipmentAddress;
	use Quellabs\Shipments\Contracts\ShipmentOptionException;
	use Quellabs\Shipments\Contracts\ShipmentCancellationException;
	use Quellabs\Shipments\Contracts\ShipmentExchangeException;
	use Quellabs\Shipments\Contracts\ShipmentInitiationException;
	use Quellabs\Shipments\Contracts\ShipmentLabelException;
	use Quellabs\Shipments\Contracts\ShipmentProviderInterface;
	use Quellabs\Shipments\Contracts\ShipmentRequest;
	use Quellabs\Shipments\Contracts\ShipmentResult;
	use Quellabs\Shipments\Contracts\ShipmentState;
	use Quellabs\Shipments\PostNL\Transformers\DeliveryOptionTransformer;
	use Quellabs\Shipments\PostNL\Transformers\LabelTransformer;
	use Quellabs\Shipments\PostNL\Transformers\PickupOptionTransformer;
	use Quellabs\Shipments\PostNL\Transformers\ShipmentResultTransformer;
	use Quellabs\Shipments\PostNL\Transformers\ShipmentStateTransformer;
	
	class Driver implements ShipmentProviderInterface {
		
		use GatewayHelpers;
		
		/**
		 * Driver name — stored in ShipmentResult::$provider and ShipmentState::$provider.
		 * Used by ShipmentRouter::exchange() to re-resolve this driver later.
		 */
		const string DRIVER_NAME = 'postnl';
		
		/**
		 * Active configuration, applied by the discovery system after instantiation.
		 * @var array<string, mixed>
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
		private const array MODULE_PRODUCT_MAP = [
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
		 * @return array<string, mixed>
		 */
		public function getConfig(): array {
			return array_replace_recursive($this->getDefaults(), $this->config);
		}
		
		/**
		 * Applies configuration to this driver instance.
		 * Called by the discovery system after instantiation, before any other methods.
		 * @param array<string, mixed> $config
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = $config;
		}
		
		/**
		 * Returns default configuration values for this driver.
		 * @return array<string, mixed>
		 */
		public function getDefaults(): array {
			return [
				'api_key'             => '',
				'api_key_test'        => '',
				'test_mode'           => false,
				'customer_code'       => '',
				'customer_number'     => '',
				'collection_location' => '',
				'delivery_options'    => ['Daytime'],
				'sender_address'      => [],
			];
		}
		
		/**
		 * Creates a parcel via the PostNL Shipment API and returns a structured result.
		 *
		 * The PostNL API returns the barcode and label inline in the creation response,
		 * but the label content is discarded here. Call getLabelUrl() explicitly when
		 * you need the label — it re-requests it from the PostNL Confirming/Label endpoint.
		 *
		 * @param ShipmentRequest $request
		 * @return ShipmentResult
		 * @throws ShipmentInitiationException
		 */
		public function create(ShipmentRequest $request): ShipmentResult {
			// Fetch shipment code from module map
			$productInfo = self::MODULE_PRODUCT_MAP[$request->shippingModule] ?? null;
			
			// If not found, throw error
			if ($productInfo === null) {
				throw new ShipmentInitiationException(
					self::DRIVER_NAME,
					'unknown_module',
					"Unknown shipping module '{$request->shippingModule}'"
				);
			}
			
			// Create the payload
			$config = $this->getConfig();
			
			$shipmentPayload = [
				'Customer'  => [
					'Address'            => array_filter([
						'AddressType' => '02',
						'City'        => $this->arrayGetString($config, 'sender_address.city') ?? '',
						'CompanyName' => $this->arrayGetString($config, 'sender_address.company') ?? '',
						'Countrycode' => $this->arrayGetString($config, 'sender_address.country') ?? 'NL',
						'HouseNr'     => $this->arrayGetString($config, 'sender_address.houseNumber') ?? '',
						'Street'      => $this->arrayGetString($config, 'sender_address.street') ?? '',
						'Zipcode'     => $this->arrayGetString($config, 'sender_address.postalCode') ?? '',
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
							], fn($v) => $v !== null) : null,
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
			
			// Merge extradata into payload
			if (!empty($request->extraData)) {
				$shipmentPayload = array_merge_recursive($shipmentPayload, $request->extraData);
			}
			
			// Call the API
			$result = $this->getGateway()->createShipment($shipmentPayload);
			
			// If that failed, throw an error
			if ($result['request']['result'] === 0) {
				throw new ShipmentInitiationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Fetch response
			$response = $result['response'] ?? [];
			
			// Map the API response to a ShipmentResult
			return (new ShipmentResultTransformer())->transform(
				$response,
				$request->reference,
				$request->deliveryAddress->postalCode,
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
			// Call the API to delete the shipment
			$result = $this->getGateway()->deleteShipment($request->parcelId);
			
			// If that failed, throw an error
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
			
			// Return the result
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
			// Fetch the status
			$result = $this->getGateway()->getStatus($parcelId);
			
			// If that failed, throw an error
			if ($result['request']['result'] === 0) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Fetch the shipment data
			$exchangeResponse = $result['response'] ?? [];
			
			// Map the API response to a ShipmentState
			return (new ShipmentStateTransformer())->transform($exchangeResponse, $parcelId);
		}
		
		/**
		 * Returns normalized home delivery options for the given module.
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
			// If no address passed, bail
			if ($address === null) {
				return [];
			}
			
			// Transform shipping module to product id
			$productInfo = self::MODULE_PRODUCT_MAP[$shippingModule] ?? null;
			
			// If none found, or this is a pickup module, bail.
			if ($productInfo === null || $this->isPickupModule($productInfo['productCode'])) {
				return [];
			}
			
			// Call the API to fetch timeframes
			$startDate = (new \DateTimeImmutable('+1 day'))->format('d-m-Y');
			$endDate = (new \DateTimeImmutable('+6 days'))->format('d-m-Y');
			$configOptions = $this->getConfig()['delivery_options'];
			
			if (is_array($configOptions)) {
				$options = array_values(array_filter($configOptions, 'is_string'));
			} else {
				$options = ['Daytime'];
			}
			
			$result = $this->getGateway()->getTimeframes(
				$address->postalCode,
				$address->houseNumber,
				$address->country,
				$startDate,
				$endDate,
				$options,
			);
			
			// If that failed, return empty array
			if ($result['request']['result'] === 0) {
				throw new ShipmentOptionException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Map the API response to DeliveryOption[]
			return (new DeliveryOptionTransformer())->transform(
				$result['response'] ?? [],
				$productInfo['productCode'],
			);
		}
		
		/**
		 * Calls the PostNL Location API to find the nearest service points.
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return PickupOption[]
		 */
		public function getPickupOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			// If no address passed, return empty array
			if ($address === null) {
				return [];
			}
			
			// Fetch nearest locations from PostNL API
			$result = $this->getGateway()->getNearestLocations(
				$address->postalCode,
				$address->houseNumber,
				$address->country,
			);
			
			// If that failed, return empty array
			if ($result['request']['result'] === 0) {
				return [];
			}
			
			// Map the API response to PickupOption[]
			return (new PickupOptionTransformer())->transform($result['response'] ?? []);
		}
		
		/**
		 * Returns the label for a given parcel barcode as a base64 data URI.
		 * @param string $parcelId The PostNL barcode from ShipmentResult::$parcelId
		 * @return string
		 * @throws ShipmentLabelException
		 */
		public function getLabelUrl(string $parcelId): string {
			// Fetch label from PostNL api
			$result = $this->getGateway()->getLabel($parcelId);
			
			// If that failed, throw error
			if ($result['request']['result'] === 0) {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// Map the API response to a base64 data URI
			return (new LabelTransformer())->transform($result['response'] ?? [], $parcelId);
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
	}