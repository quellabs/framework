<?php
	
	namespace Quellabs\Shipments\SendCloud;
	
	use Quellabs\Shipments\Contracts\CancelRequest;
	use Quellabs\Shipments\Contracts\CancelResult;
	use Quellabs\Shipments\Contracts\ShipmentAddress;
	use Quellabs\Shipments\Contracts\ShipmentCancellationException;
	use Quellabs\Shipments\Contracts\ShipmentCreationException;
	use Quellabs\Shipments\Contracts\ShipmentExchangeException;
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
		const DRIVER_NAME = 'sendcloud';
		
		/**
		 * Active configuration, applied by the discovery system after instantiation.
		 * @var array
		 */
		private array $config = [];
		
		/**
		 * Gateway instance, constructed lazily on first use to ensure config is available.
		 * @var SendCloudGateway|null
		 */
		private ?SendCloudGateway $gateway = null;
		
		/**
		 * Maps our internal module names to the SendCloud carrier names used in service point
		 * queries. A module may cover multiple carriers (e.g. 'sendcloud_multi' could list all).
		 *
		 * Add entries here whenever a new module is introduced in getMetadata().
		 */
		private const MODULE_CARRIER_MAP = [
			'sendcloud_postnl'   => ['postnl'],
			'sendcloud_dhl'      => ['dhl'],
			'sendcloud_dpd'      => ['dpd'],
			'sendcloud_ups'      => ['ups'],
			'sendcloud_bpost'    => ['bpost'],
			'sendcloud_mondial'  => ['mondial_relay'],
			'sendcloud_multi'    => ['postnl', 'dhl', 'dpd', 'ups', 'bpost', 'mondial_relay'],
		];
		
		/**
		 * Maps SendCloud parcel status IDs to our normalised ShipmentStatus values.
		 * SendCloud status IDs are integers; partial mapping is intentional — unmapped
		 * IDs fall through to ShipmentStatus::Unknown.
		 *
		 * @see https://docs.sendcloud.com/api/v2/#parcel-statuses
		 */
		private const STATUS_MAP = [
			1  => ShipmentStatus::Created,           // Announced (label created, not yet handed to carrier)
			2  => ShipmentStatus::ReadyToSend,       // Ready to send (announced, awaiting carrier pickup)
			3  => ShipmentStatus::InTransit,         // En route to sorting center
			4  => ShipmentStatus::Delivered,         // Delivered
			5  => ShipmentStatus::DeliveryFailed,    // Delivery attempt failed
			6  => ShipmentStatus::AwaitingPickup,    // At service point / pickup location
			7  => ShipmentStatus::ReturnedToSender,  // Returning to sender
			11 => ShipmentStatus::InTransit,         // Sorted
			12 => ShipmentStatus::InTransit,         // In transit
			13 => ShipmentStatus::OutForDelivery,    // Out for delivery
			91 => ShipmentStatus::Cancelled,         // Cancelled
			92 => ShipmentStatus::Unknown,           // Unknown
			93 => ShipmentStatus::DeliveryFailed,    // Lost in transit
			99 => ShipmentStatus::Unknown,           // No label
		];
		
		/**
		 * Returns discovery metadata for this provider.
		 * Called statically during discovery — no instantiation required.
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array {
			return [
				'driver'  => self::DRIVER_NAME,
				'modules' => array_keys(self::MODULE_CARRIER_MAP),
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
				'public_key'     => '',
				'secret_key'     => '',
				'partner_id'     => '',
				'webhook_secret' => '',
				'sender_address' => [],  // Default sender address fields used in createParcel()
				'from_country'   => 'NL',
			];
		}
		
		/**
		 * Creates a parcel via SendCloud and returns structured result.
		 * @param ShipmentRequest $request
		 * @return ShipmentResult
		 * @throws ShipmentCreationException
		 */
		public function create(ShipmentRequest $request): ShipmentResult {
			$config = $this->getConfig();
			
			$payload = [
				'parcel' => array_filter([
					'name'                    => $request->deliveryAddress->name,
					'company_name'            => $request->deliveryAddress->company,
					'address'                 => $this->buildStreetLine($request->deliveryAddress),
					'house_number'            => $request->deliveryAddress->houseNumber,
					'city'                    => $request->deliveryAddress->city,
					'postal_code'             => $request->deliveryAddress->postalCode,
					'country'                 => ['iso_2' => $request->deliveryAddress->country],
					'email'                   => $request->deliveryAddress->email,
					'telephone'               => $request->deliveryAddress->phone,
					'order_number'            => $request->reference,
					'weight'                  => round($request->weightGrams / 1000, 3),
					'shipment'                => ['id' => $request->methodId],
					'insured_value'           => $request->declaredValueCents > 0 ? $request->declaredValueCents : null,
					'to_service_point'        => $request->servicePointId,
					'request_label'           => $request->requestLabel,
					'apply_shipping_rules'    => true,
				], fn($v) => $v !== null && $v !== '' && $v !== []),
			];
			
			// Merge in any provider-specific extra fields the caller supplied
			if (!empty($request->extraData)) {
				$payload['parcel'] = array_merge($payload['parcel'], $request->extraData);
			}
			
			$result = $this->getGateway()->createParcel($payload);
			
			if ($result['request']['result'] === 0) {
				throw new ShipmentCreationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			$parcel = $result['response']['parcel'];
			
			return new ShipmentResult(
				provider:     self::DRIVER_NAME,
				parcelId:     (string)$parcel['id'],
				reference:    $request->reference,
				trackingCode: $parcel['tracking_number'] ?? null,
				trackingUrl:  $parcel['tracking_url'] ?? null,
				labelUrl:     $parcel['label']['label_printer'] ?? $parcel['label']['normal_printer'][0] ?? null,
				carrierName:  $parcel['carrier']['name'] ?? null,
				rawResponse:  $parcel,
			);
		}
		
		/**
		 * Cancels a parcel via SendCloud.
		 * @param CancelRequest $request
		 * @return CancelResult
		 * @throws ShipmentCancellationException
		 */
		public function cancel(CancelRequest $request): CancelResult {
			$result = $this->getGateway()->cancelParcel($request->parcelId);
			
			if ($result['request']['result'] === 0) {
				throw new ShipmentCancellationException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			$isAccepted = ($result['response']['status'] ?? '') === 'cancelled';
			
			return new CancelResult(
				provider:  self::DRIVER_NAME,
				parcelId:  $request->parcelId,
				reference: $request->reference,
				accepted:  $isAccepted,
				message:   $isAccepted ? null : ($result['response']['message'] ?? null),
			);
		}
		
		/**
		 * Fetches the current state of a parcel from SendCloud.
		 * Used to reconcile missed webhooks or poll for status on demand.
		 * @param string $parcelId
		 * @return ShipmentState
		 * @throws ShipmentExchangeException
		 */
		public function exchange(string $parcelId): ShipmentState {
			$result = $this->getGateway()->getParcel($parcelId);
			
			if ($result['request']['result'] === 0) {
				throw new ShipmentExchangeException(
					self::DRIVER_NAME,
					$result['request']['errorId'],
					$result['request']['errorMessage']
				);
			}
			
			return $this->buildStateFromParcel($result['response']['parcel']);
		}
		
		/**
		 * Returns available shipping methods for the given module.
		 * Filters by the module's carrier set and the configured sender country.
		 * @param string $shippingModule
		 * @return array
		 */
		public function getShippingOptions(string $shippingModule): array {
			$config = $this->getConfig();
			$result = $this->getGateway()->getShippingMethods($config['from_country'] ?? null);
			
			if ($result['request']['result'] === 0) {
				return [];
			}
			
			$carriers = self::MODULE_CARRIER_MAP[$shippingModule] ?? [];
			$methods  = $result['response']['shipping_methods'] ?? [];
			
			// Filter to methods belonging to the carriers this module covers.
			// If no carriers are mapped, return everything.
			if (!empty($carriers)) {
				$methods = array_values(array_filter($methods, function (array $method) use ($carriers): bool {
					$carrierName = strtolower($method['carrier'] ?? '');
					return in_array($carrierName, $carriers, true);
				}));
			}
			
			return $methods;
		}
		
		/**
		 * Verifies the HMAC-SHA256 signature on an incoming SendCloud webhook.
		 * @param string $rawBody   The raw (un-decoded) request body
		 * @param string $signature Value of the Sendcloud-Signature header
		 * @return bool
		 */
		public function verifyWebhookSignature(string $rawBody, string $signature): bool {
			return $this->getGateway()->verifyWebhookSignature($rawBody, $signature, $this->getConfig()['webhook_secret']);
		}
		
		/**
		 * Builds a ShipmentState from a raw parcel array returned by the SendCloud API.
		 * Used by both exchange() (API poll) and the controller's webhook handler.
		 * @param array $parcel The 'parcel' key from the SendCloud API response
		 * @return ShipmentState
		 */
		public function buildStateFromParcel(array $parcel): ShipmentState {
			$statusId     = (int)($parcel['status']['id'] ?? 92);
			$statusLabel  = $parcel['status']['message'] ?? null;
			$internalState = $parcel['status']['id'] . ':' . ($parcel['status']['message'] ?? 'unknown');
			
			$status = self::STATUS_MAP[$statusId] ?? ShipmentStatus::Unknown;
			
			return new ShipmentState(
				provider:      self::DRIVER_NAME,
				parcelId:      (string)$parcel['id'],
				reference:     $parcel['order_number'] ?? '',
				state:         $status,
				trackingCode:  $parcel['tracking_number'] ?? null,
				trackingUrl:   $parcel['tracking_url'] ?? null,
				statusMessage: $statusLabel,
				internalState: $internalState,
				metadata:      array_filter([
					'carrierId'      => $parcel['carrier']['id'] ?? null,
					'carrierName'    => $parcel['carrier']['name'] ?? null,
					'labelUrl'       => $parcel['label']['label_printer'] ?? null,
					'servicePointId' => $parcel['to_service_point'] ?? null,
					'weightKg'       => $parcel['weight'] ?? null,
				], fn($v) => $v !== null),
			);
		}
		
		/**
		 * Lazily instantiates and returns the SendCloud gateway.
		 * @return SendCloudGateway
		 */
		private function getGateway(): SendCloudGateway {
			return $this->gateway ??= new SendCloudGateway($this);
		}
		
		/**
		 * Builds a full street line from an address, appending suffix when present.
		 * SendCloud's 'address' field expects street + house number combined.
		 * @param ShipmentAddress $address
		 * @return string
		 */
		private function buildStreetLine(ShipmentAddress $address): string {
			$line = trim($address->street . ' ' . $address->houseNumber);
			
			if (!empty($address->houseNumberSuffix)) {
				$line .= ' ' . $address->houseNumberSuffix;
			}
			
			return $line;
		}
	}