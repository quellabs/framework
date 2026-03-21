<?php
	
	namespace Quellabs\Payments;
	
	use Quellabs\Discover\Scanner\ComposerScanner;
	use Quellabs\Payments\Contracts\InitiateResult;
	use Quellabs\Payments\Contracts\PaymentInterface;
	use Quellabs\Payments\Contracts\PaymentProviderInterface;
	use Quellabs\Payments\Contracts\PaymentRequest;
	use Quellabs\Payments\Contracts\PaymentState;
	use Quellabs\Payments\Contracts\RefundRequest;
	use Quellabs\Discover\Discover;
	use Quellabs\Payments\Contracts\RefundResult;
	
	class PaymentRouter implements PaymentInterface {
		
		private array $moduleMap = [];
		private array $driverMap = [];
		private Discover $discover;
		
		/**
		 * PaymentRouter constructor.
		 * Discovers all payment providers via composer metadata and builds the module map.
		 */
		public function __construct() {
			// Run discovery to populate provider definitions and collected metadata
			$this->discover = new Discover();
			$this->discover->addScanner(new ComposerScanner("payments"));
			$this->discover->discover();
			
			// Iterate all discovered provider classes and build the module map
			foreach ($this->discover->getProviderClasses() as $class) {
				// Skip classes that don't implement PaymentProviderInterface
				if (!is_subclass_of($class, PaymentProviderInterface::class)) {
					continue;
				}
				
				// The metadata should include a list of modules
				$metadata = $class::getMetadata();
				
				// Skip providers that declare no modules — nothing to route to
				if (empty($metadata['modules'])) {
					continue;
				}
				
				// Register each module name, guarding against duplicate registrations
				// across different provider packages
				foreach ($metadata['modules'] as $module) {
					if (isset($this->moduleMap[$module])) {
						throw new \RuntimeException("Duplicate payment module '{$module}' registered by {$class} and {$this->moduleMap[$module]}");
					}
					
					$this->moduleMap[$module] = $class;
				}

				// Register the driver name if provided, allowing exchange() and getRefunds()
				// to be called with a stable driver name instead of a module name.
				if (!empty($metadata['driver'])) {
					$this->driverMap[$metadata['driver']] = $class;
				}
			}
		}
		
		/**
		 * Initiate a payment using the provider registered for the request's payment module
		 * @param PaymentRequest $request
		 * @return InitiateResult
		 */
		public function initiate(PaymentRequest $request): InitiateResult {
			return $this->resolve($request->paymentModule)->initiate($request);
		}
		
		/**
		 * Refund a payment using the provider registered for the refund's payment module
		 * @param RefundRequest $request
		 * @return RefundResult
		 */
		public function refund(RefundRequest $request): RefundResult {
			return $this->resolve($request->paymentModule)->refund($request);
		}
		
		/**
		 * Returns payment options for the given module
		 * @param string $paymentModule
		 * @return array
		 */
		public function getPaymentOptions(string $paymentModule): array {
			return $this->resolve($paymentModule)->getPaymentOptions($paymentModule);
		}
		
		/**
		 * Returns all registered module names across all discovered providers
		 * @return array
		 */
		public function getRegisteredModules(): array {
			return array_keys($this->moduleMap);
		}
		
		/**
		 * Resolves a provider instance for the given module name.
		 * @param string $module
		 * @return PaymentProviderInterface
		 * @throws \RuntimeException
		 */
		private function resolve(string $module): PaymentProviderInterface {
			$class = $this->moduleMap[$module] ?? throw new \RuntimeException("No payment provider registered for module '{$module}'");
			return $this->discover->get($class);
		}

		/**
		 * Resolves a provider instance by driver name (e.g. 'rabosmartpay', 'paynl').
		 * @param string $driver
		 * @return PaymentProviderInterface
		 * @throws \RuntimeException
		 */
		private function resolveDriver(string $driver): PaymentProviderInterface {
			$class = $this->driverMap[$driver] ?? throw new \RuntimeException("No payment provider registered for driver '{$driver}'");
			return $this->discover->get($class);
		}
		
		/**
		 * Fetch the current state of a payment for reconciliation of missed webhooks.
		 * @param string $driver Driver name as returned in PaymentState::$provider (e.g. 'rabosmartpay')
		 * @param string $transactionId Provider-assigned transaction ID from InitiateResult
		 * @param array $extraData Optional additional data to pass to the provider
		 * @return PaymentState
		 */
		public function exchange(string $driver, string $transactionId, array $extraData = []): PaymentState {
			return $this->resolveDriver($driver)->exchange($transactionId, $extraData);
		}
		
		/**
		 * Returns all refunds issued for the given transaction.
		 * @param string $driver Driver name as returned in PaymentState::$provider (e.g. 'rabosmartpay')
		 * @param string $paymentReference
		 * @return array
		 */
		public function getRefunds(string $driver, string $paymentReference): array {
			return $this->resolveDriver($driver)->getRefunds($paymentReference);
		}
	}