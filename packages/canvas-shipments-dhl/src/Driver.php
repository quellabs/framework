<?php
	
	namespace Quellabs\Shipments\DHL;
	
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
		const DRIVER_NAME = 'dhl';
		
		/**
		 * Active configuration, applied by the discovery system after instantiation.
		 * @var array
		 */
		private array $config = [];
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var DHLGateway|null
		 */
		private ?DHLGateway $gateway = null;
		
		/**
		 * Maps our internal module names to DHL product keys.
		 *
		 * DHL Parcel NL product keys (from the capabilities endpoint):
		 *   PARCEL_CONNECT   — domestic NL and cross-border EU delivery
		 *   MAILBOX_PACKAGE  — brievenbuspakket (fits through a letterbox)
		 *   EXPRESS          — next-day before 11:00
		 *
		 * Products are enforced per account contract; not all keys are available
		 * to every DHL account. Unknown module names are rejected in create().
		 *
		 * @see https://api-gw.dhlparcel.nl/docs/#/Capabilities
		 */
		private const MODULE_PRODUCT_MAP = [
			'dhl_parcel'  => 'PARCEL_CONNECT',
			'dhl_mailbox' => 'MAILBOX_PACKAGE',
			'dhl_express' => 'EXPRESS',
		];
		
		/**
		 * Maps DHL track-and-trace status categories + status strings to our
		 * normalised ShipmentStatus values.
		 *
		 * DHL returns events as { "category": "...", "status": "..." }.
		 * We inspect the most recent event's category first, then fall back to status.
		 *
		 * @see https://api-gw.dhlparcel.nl/docs/guide/chapters/05-track-and-trace.html
		 */
		private const CATEGORY_MAP = [
			'DATA_RECEIVED' => ShipmentStatus::Created,
			'LEG'           => ShipmentStatus::Created,
			'UNDERWAY'      => ShipmentStatus::InTransit,
			'CUSTOMS'       => ShipmentStatus::InTransit,
			'IN_DELIVERY'   => ShipmentStatus::OutForDelivery,
			'DELIVERED'     => ShipmentStatus::Delivered,
			'EXCEPTION'     => ShipmentStatus::DeliveryFailed,
			'PROBLEM'       => ShipmentStatus::DeliveryFailed,
			'INTERVENTION'  => ShipmentStatus::DeliveryFailed,
			'UNKNOWN'       => ShipmentStatus::Unknown,
		];
		
		/**
		 * Fine-grained status overrides applied after category mapping.
		 * These cover statuses whose meaning differs from their parent category.
		 */
		private const STATUS_OVERRIDE_MAP = [
			'DELIVERED_AT_PS'  => ShipmentStatus::AwaitingPickup,
			'PS_ARRIVED'       => ShipmentStatus::AwaitingPickup,
			'RETURN_TO_SENDER' => ShipmentStatus::ReturnedToSender,
			'CANCELLED'        => ShipmentStatus::Cancelled,
			'LOST'             => ShipmentStatus::Lost,
			'DESTROYED'        => ShipmentStatus::Destroyed,
		];
		
		/**
		 * Maps DHL parcel type keys to their maximum weight in grams.
		 *
		 * Entries are ordered lightest-first so resolveParcelType() can iterate and return
		 * the first type whose max weight accommodates the shipment.
		 *
		 * Weight limits per DHL documentation:
		 *   SMALL  — up to 2 kg   (2 000 g),  max 38 × 26 × 10 cm
		 *   MEDIUM — up to 10 kg  (10 000 g), max 58 × 38 × 37 cm
		 *   LARGE  — up to 20 kg  (20 000 g), max 100 × 50 × 50 cm
		 *   XL     — up to 31.5 kg (31 500 g)
		 *
		 * Always verify limits against the capabilities endpoint for your destination
		 * country, as they can vary per route.
		 *
		 * @see https://api-gw.dhlparcel.nl/docs/#/Parcel%20types
		 */
		private const PARCEL_TYPE_WEIGHT_MAP = [
			'SMALL'  => 2_000,
			'MEDIUM' => 10_000,
			'LARGE'  => 20_000,
			'XL'     => 31_500,
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
				'user_id'        => '',
				'api_key'        => '',
				'user_id_test'   => '',
				'api_key_test'   => '',
				'account_id'     => '',
				'test_mode'      => false,
				'sender_address' => [],
			];
		}
		
		/**
		 * Creates a DHL shipment and returns a structured result.
		 *
		 * Flow:
		 *   1. POST /shipments  — returns trackerCode (barcode) + labelId in the response.
		 *   2. If requestLabel: GET /labels?trackerCodeFilter=  — fetches the label PDF URL.
		 *
		 * The shipmentId sent to DHL is a client-generated UUID used for idempotency.
		 * DHL's stable identifier for later exchange() and label retrieval is the trackerCode.
		 * We store the trackerCode as ShipmentResult::$parcelId for consistency with the contract.
		 *
		 * @param ShipmentRequest $request
		 * @return ShipmentResult
		 * @throws ShipmentCreationException
		 */
		public function create(ShipmentRequest $request): ShipmentResult {
			$productKey = self::MODULE_PRODUCT_MAP[$request->shippingModule] ?? null;
			
			if ($productKey === null) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'unknown_module',
					"Unknown shipping module '{$request->shippingModule}'"
				);
			}
			
			// Fetch configuration data
			$config = $this->getConfig();
			
			// DHL uses a caller-generated UUID as the idempotency key for the shipment.
			// This is NOT the parcel identifier; the trackerCode returned by DHL serves that role.
			$shipmentUuid = $this->generateUuid();
			
			// Build options
			$options = [['key' => 'DOOR']];
			
			if ($request->servicePointId !== null) {
				// When delivering to a DHL ServicePoint, replace DOOR with PS and provide the location ID
				$options = [['key' => 'PS', 'input' => $request->servicePointId]];
			}
			
			if ($request->description !== null) {
				$options[] = ['key' => 'REFERENCE', 'input' => substr($request->description, 0, 15)];
			}
			
			// Merge any extra shipment options from extraData['options']
			if (!empty($request->extraData['options'])) {
				$options = array_merge($options, $request->extraData['options']);
			}
			
			// Build payload
			$payload = [
				'shipmentId'     => $shipmentUuid,
				'orderReference' => $request->reference,
				'receiver'       => $this->buildReceiver($request->deliveryAddress),
				'shipper'        => $this->buildShipper($config),
				'accountId'      => $config['account_id'],
				'options'        => $options,
				'pieces'         => [[
					'parcelType' => $this->resolveParcelType($request),
					'quantity'   => 1,
					'weight'     => round($request->weightGrams / 1000, 3),
				]],
			];
			
			// Merge any top-level extra fields (e.g. returnLabel, customsDeclaration).
			// 'options' and 'parcelType' are driver-handled keys — exclude them from the
			// top-level merge to prevent them appearing as stray fields in the DHL payload.
			if (!empty($request->extraData)) {
				$extraWithoutOptions = array_diff_key($request->extraData, ['options' => true, 'parcelType' => true]);
				$payload = array_merge($payload, $extraWithoutOptions);
			}
			
			// Call API
			$result = $this->getGateway()->createShipment($payload);
			
			// If that failed, throw error
			if ($result['request']['result'] === 0) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// DHL returns the trackerCode (barcode) in the creation response.
			// This is the stable identifier — use it as parcelId throughout the system.
			$trackerCode = $result['response']['trackerCode'] ?? null;
			
			if ($trackerCode === null || $trackerCode === '') {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'missing_tracker_code',
					'DHL did not return a tracker code in the shipment creation response'
				);
			}
			
			// Try to fetch label url
			$labelUrl = null;
			
			if ($request->requestLabel) {
				try {
					$labelUrl = $this->getLabelUrl($trackerCode);
				} catch (ShipmentLabelException) {
					// Label retrieval failing is non-fatal at creation time.
					// The caller can retry via getLabelUrl() when needed.
					$labelUrl = null;
				}
			}
			
			// Return response
			return new ShipmentResult(
				provider: self::DRIVER_NAME,
				parcelId: $trackerCode,
				reference: $request->reference,
				trackingCode: $trackerCode,
				trackingUrl: $this->buildTrackingUrl($trackerCode, $request->deliveryAddress->postalCode),
				labelUrl: $labelUrl,
				carrierName: 'DHL',
				rawResponse: $result['response'],
			);
		}
		
		/**
		 * DHL Parcel NL does not expose a cancellation endpoint in its public API.
		 * Shipments must be cancelled via the My DHL Parcel portal or the Interventions endpoint,
		 * which requires a separate agreement with DHL.
		 *
		 * @param CancelRequest $request
		 * @return CancelResult
		 * @throws ShipmentCancellationException always
		 */
		public function cancel(CancelRequest $request): CancelResult {
			throw new ShipmentCancellationException(
				self::DRIVER_NAME,
				'not_supported',
				'DHL Parcel NL does not support programmatic parcel cancellation via the public API. ' .
				'Cancel manually via the My DHL Parcel portal, or use the Interventions endpoint ' .
				'if enabled on your account.'
			);
		}
		
		/**
		 * Fetches the current state of a DHL parcel by tracker code (barcode).
		 *
		 * DHL's track-and-trace endpoint returns an array of events in chronological order.
		 * We derive the current status from the most recent event's category, with fine-grained
		 * overrides applied per individual status string.
		 *
		 * @param string $parcelId The DHL tracker code from ShipmentResult::$parcelId
		 * @return ShipmentState
		 * @throws ShipmentExchangeException
		 */
		public function exchange(string $parcelId): ShipmentState {
			$result = $this->getGateway()->getTrackTrace($parcelId);
			
			if ($result['request']['result'] === 0) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			$events = $result['response'] ?? [];
			
			if (empty($events)) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					'not_found',
					"DHL returned no tracking events for tracker code {$parcelId}"
				);
			}
			
			return $this->buildStateFromEvents($parcelId, $events);
		}
		
		/**
		 * DHL Parcel NL does not provide a delivery timeslot options API
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return DeliveryOption[]
		 */
		public function getDeliveryOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			return [];
		}
		
		/**
		 * Returns nearby DHL ServicePoints (pickup points) for the given address.
		 *
		 * DHL's parcel shop finder requires the country code and at least a postal code or city.
		 * Results are ordered by proximity (nearest first). Returns an empty array when no
		 * address is provided, since DHL requires a search origin.
		 *
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return PickupOption[]
		 */
		public function getPickupOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			if ($address === null) {
				return [];
			}
			
			$result = $this->getGateway()->getParcelShops(
				$address->country,
				$address->postalCode,
				$address->city
			);
			
			if ($result['request']['result'] === 0) {
				return [];
			}
			
			$options = [];
			
			foreach ($result['response'] as $shop) {
				$addr = $shop['address'] ?? [];
				
				$options[] = new PickupOption(
					locationCode: $shop['id'] ?? '',
					name: $shop['name'] ?? '',
					street: $addr['street'] ?? '',
					houseNumber: (string)($addr['number'] ?? ''),
					postalCode: $addr['postalCode'] ?? $addr['zipCode'] ?? '',
					city: $addr['city'] ?? '',
					country: $addr['countryCode'] ?? $address->country,
					carrierName: 'DHL',
					latitude: isset($shop['geoLocation']['latitude']) ? (float)$shop['geoLocation']['latitude'] : null,
					longitude: isset($shop['geoLocation']['longitude']) ? (float)$shop['geoLocation']['longitude'] : null,
					distanceMetres: isset($shop['distance']) ? (int)$shop['distance'] : null,
					metadata: array_filter([
						'shopType'     => $shop['shopType'] ?? null,
						'openingTimes' => $shop['openingTimes'] ?? null,
					], fn($v) => $v !== null),
				);
			}
			
			return $options;
		}
		
		/**
		 * Returns the URL where the label PDF for the given tracker code can be downloaded.
		 *
		 * DHL requires two API calls to obtain a label:
		 *   1. GET /labels?trackerCodeFilter={code}  — returns the internal label ID.
		 *   2. GET /labels/{id}                      — returns the PDF binary (Accept: application/pdf).
		 *
		 * Because returning a raw PDF as a "URL" does not fit the ShipmentResult::$labelUrl contract,
		 * this method returns the API gateway URL for the label PDF endpoint. Callers that need
		 * the actual PDF bytes should use DHLGateway::getLabelPdf() directly.
		 *
		 * @param string $parcelId The DHL tracker code
		 * @return string
		 * @throws ShipmentLabelException
		 */
		public function getLabelUrl(string $parcelId): string {
			// Call the API to fetch the label
			$result = $this->getGateway()->getLabelId($parcelId);
			
			// If that failed, throw an error
			if ($result['request']['result'] === 0) {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			// The labels endpoint returns an array; we expect exactly one label per tracker code
			$labelId = $result['response'][0]['labelId'] ?? null;
			
			if ($labelId === null) {
				throw new ShipmentLabelException(
					self::DRIVER_NAME,
					'missing_label_id',
					"DHL returned no label ID for tracker code {$parcelId}"
				);
			}
			
			// Return the direct API endpoint URL for the label PDF.
			// The gateway injects the Authorization header; callers proxying this URL
			// must fetch it server-side with a valid Bearer token.
			// Build the label URL against the correct environment base URL.
			// Callers must fetch this server-side with a valid Bearer token.
			$config = $this->getConfig();
			$baseUrl = $config['test_mode'] ? DHLGateway::BASE_URL_TEST : DHLGateway::BASE_URL_LIVE;
			return $baseUrl . "/labels/{$labelId}";
		}
		
		/**
		 * Builds a ShipmentState from the array of track-and-trace events returned by DHL.
		 * Used by exchange(). The last event in the array represents the current state.
		 * @param string $parcelId The DHL tracker code
		 * @param array $events Array from the track-trace response (chronological)
		 * @return ShipmentState
		 */
		public function buildStateFromEvents(string $parcelId, array $events): ShipmentState {
			// Events are ordered chronologically; the last entry is the current state
			$latest = end($events);
			$category = strtoupper($latest['category'] ?? 'UNKNOWN');
			$statusCode = strtoupper($latest['status'] ?? 'UNKNOWN');
			
			// Fine-grained overrides take precedence over category-level mapping
			$status = self::STATUS_OVERRIDE_MAP[$statusCode]
				?? self::CATEGORY_MAP[$category]
				?? ShipmentStatus::Unknown;
			
			// deliveredAt is set at the top level of the response when the parcel is delivered
			$deliveredAt = $events[0]['deliveredAt'] ?? null;
			$barcode = $events[0]['barcode'] ?? $parcelId;
			
			// Extract postal code from the most recent event if provided (needed for tracking URL)
			$postalCode = $latest['postalCode'] ?? $events[0]['postalCode'] ?? '';
			
			// Planned delivery window, present in UNDERWAY events after hub sorting
			$plannedWindow = $latest['plannedDeliveryTimeframe'] ?? null;
			
			return new ShipmentState(
				provider: self::DRIVER_NAME,
				parcelId: $barcode,
				reference: $latest['reference'] ?? '',
				state: $status,
				trackingCode: $barcode,
				trackingUrl: $this->buildTrackingUrl($barcode, $postalCode),
				statusMessage: $this->buildStatusMessage($category, $statusCode, $plannedWindow),
				internalState: $statusCode,
				metadata: array_filter([
					'category'              => $category,
					'deliveredAt'           => $deliveredAt,
					'plannedDeliveryWindow' => $plannedWindow,
					'facility'              => $latest['facility'] ?? null,
				], fn($v) => $v !== null),
			);
		}
		
		/**
		 * Resolves the DHL parcel type key for a shipment request.
		 *
		 * If the caller has supplied a 'parcelType' key inside ShipmentRequest::$extraData,
		 * that value is used as-is — no weight check is performed, giving the caller full
		 * control when needed (e.g. MAILBOX_PACKAGE has its own size constraints).
		 *
		 * Otherwise, the parcel type is derived from ShipmentRequest::$weightGrams by finding
		 * the smallest DHL type whose maximum weight accommodates the shipment. This ensures
		 * the cheapest appropriate product is selected automatically.
		 *
		 * Throws ShipmentCreationException when the weight exceeds XL (31 500 g / 31.5 kg),
		 * since DHL has no standard product above that limit and the API would reject it anyway.
		 *
		 * @param ShipmentRequest $request
		 * @return string DHL parcel type key (e.g. 'SMALL', 'MEDIUM', 'LARGE', 'XL')
		 * @throws ShipmentCreationException when weight exceeds the XL limit
		 */
		private function resolveParcelType(ShipmentRequest $request): string {
			// Caller override: honor an explicit parcelType in extraData without weight-checking it
			if (isset($request->extraData['parcelType'])) {
				return (string)$request->extraData['parcelType'];
			}
			
			// Fetch weight
			$weightGrams = $request->weightGrams;
			
			// Auto-select parcel type based on weight
			foreach (self::PARCEL_TYPE_WEIGHT_MAP as $type => $maxGrams) {
				if ($weightGrams <= $maxGrams) {
					return $type;
				}
			}
			
			// Weight exceeds limit, throw exception
			$maxKg = max(self::PARCEL_TYPE_WEIGHT_MAP) / 1000;
			
			throw new ShipmentCreationException(
				self::DRIVER_NAME,
				'weight_exceeds_limit',
				"Shipment weight of {$weightGrams} g exceeds the maximum DHL parcel weight of {$maxKg} kg (XL). " .
				"Split the shipment into multiple parcels or use a freight service."
			);
		}
		
		/**
		 * Lazily instantiates and returns the DHL gateway.
		 * @return DHLGateway
		 */
		private function getGateway(): DHLGateway {
			return $this->gateway ??= new DHLGateway($this);
		}
		
		/**
		 * Builds the receiver block for the DHL shipment payload.
		 * DHL separates name into firstName/lastName; we treat the full name as lastName
		 * when no split is available, which is what most DHL integrations do.
		 * @param ShipmentAddress $address
		 * @return array
		 */
		private function buildReceiver(ShipmentAddress $address): array {
			$receiver = [
				'name'    => $this->buildNameBlock($address->name, $address->company),
				'address' => array_filter([
					'countryCode' => $address->country,
					'postalCode'  => $address->postalCode,
					'city'        => $address->city,
					'street'      => $address->street,
					'number'      => $address->houseNumber,
					'addition'    => $address->houseNumberSuffix,
					'isBusiness'  => $address->company !== null,
				], fn($v) => $v !== null && $v !== ''),
			];
			
			if ($address->email !== null) {
				$receiver['email'] = $address->email;
			}
			
			if ($address->phone !== null) {
				$receiver['phoneNumber'] = $address->phone;
			}
			
			return $receiver;
		}
		
		/**
		 * Builds the shipper block from the configured sender_address.
		 * Throws ShipmentCreationException if no sender address is configured,
		 * since DHL requires a shipper on every label.
		 * @param array $config
		 * @return array
		 * @throws ShipmentCreationException
		 */
		private function buildShipper(array $config): array {
			$sender = $config['sender_address'];
			
			if (empty($sender)) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					'missing_sender_address',
					'DHL requires a sender address. Configure sender_address in the dhl.php config file.'
				);
			}
			
			$shipper = [
				'name'    => $this->buildNameBlock($sender['person'] ?? '', $sender['company'] ?? null),
				'address' => array_filter([
					'countryCode' => $sender['cc'] ?? 'NL',
					'postalCode'  => $sender['postal_code'] ?? '',
					'city'        => $sender['city'] ?? '',
					'street'      => $sender['street'] ?? '',
					'number'      => $sender['number'] ?? '',
					'isBusiness'  => !empty($sender['company']),
				], fn($v) => $v !== null && $v !== ''),
			];
			
			if (!empty($sender['email'])) {
				$shipper['email'] = $sender['email'];
			}
			
			if (!empty($sender['phone'])) {
				$shipper['phoneNumber'] = $sender['phone'];
			}
			
			return $shipper;
		}
		
		/**
		 * Builds a DHL name block. DHL requires at least one of firstName, lastName, or companyName.
		 * When only a full name is available (no split), we put it all in lastName.
		 * @param string $fullName
		 * @param string|null $company
		 * @return array
		 */
		private function buildNameBlock(string $fullName, ?string $company): array {
			return array_filter([
				'lastName'    => $fullName ?: null,
				'companyName' => $company,
			], fn($v) => $v !== null && $v !== '');
		}
		
		/**
		 * Constructs a human-readable status message from a category, status code, and delivery window.
		 * @param string $category
		 * @param string $status
		 * @param string|null $plannedWindow ISO interval e.g. '2026-04-03T14:00:00+01:00/2026-04-03T18:00:00+01:00'
		 * @return string|null
		 */
		private function buildStatusMessage(string $category, string $status, ?string $plannedWindow): ?string {
			$message = match ($category) {
				'DATA_RECEIVED' => 'Shipment registered',
				'LEG' => 'Shipment registered',
				'UNDERWAY' => 'Parcel in transit',
				'IN_DELIVERY' => 'Out for delivery',
				'DELIVERED' => 'Delivered',
				'EXCEPTION',
				'PROBLEM' => 'Delivery exception',
				'INTERVENTION' => 'Delivery intervention',
				default => null,
			};
			
			if ($plannedWindow !== null && $message !== null) {
				// Parse the ISO interval to extract a human-readable window
				$parts = explode('/', $plannedWindow);
				
				if (count($parts) === 2) {
					try {
						$from = new \DateTimeImmutable($parts[0]);
						$to = new \DateTimeImmutable($parts[1]);
						$message .= ' — expected ' . $from->format('d-m-Y H:i') . '–' . $to->format('H:i');
					} catch (\Throwable) {
						// If parsing fails, omit the window rather than crashing
					}
				}
			}
			
			return $message;
		}
		
		/**
		 * Constructs the public DHL track-and-trace URL for a given barcode and postal code.
		 * @param string $trackerCode
		 * @param string $postalCode
		 * @return string
		 */
		private function buildTrackingUrl(string $trackerCode, string $postalCode): string {
			// DHL NL public tracking URL; postal code unlocks extended shipment detail in the response
			return "https://www.dhlparcel.nl/nl/volg-uw-zending-0?tt={$trackerCode}&pc=" . rawurlencode($postalCode);
		}
		
		/**
		 * Generates a RFC 4122 compliant UUID v4.
		 * DHL uses UUIDs as shipment idempotency keys; PHP 8.3+ has Uuid but we support 8.1+.
		 * @return string
		 */
		private function generateUuid(): string {
			$data = random_bytes(16);
			$data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
			$data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant bits
			return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
		}
	}
