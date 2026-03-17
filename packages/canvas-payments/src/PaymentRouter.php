<?php
	
	namespace Quellabs\Payments;
	
	use Psr\Container\ContainerInterface;
	use Quellabs\Payments\Contracts\PaymentProviderInterface;
	use Quellabs\Payments\Contracts\PaymentRequest;
	use Quellabs\Payments\Contracts\PaymentResponse;
	use Quellabs\Payments\Contracts\RefundRequest;
	use Quellabs\Discover\Discover;
	use Quellabs\Discover\Scanner\MetadataCollector;
	
	class PaymentRouter {
		
		private array $moduleMap = [];
		
		/**
		 * PaymentRouter constructor.
		 * Discovers all payment providers via composer metadata and builds the module map.
		 * @param ContainerInterface $container
		 */
		public function __construct(private ContainerInterface $container) {
			foreach ($this->discoverProviders() as $class) {
				foreach ($class::getSupportedModules() as $module) {
					if (isset($this->moduleMap[$module])) {
						throw new \RuntimeException("Duplicate payment module '{$module}' registered by {$class} and {$this->moduleMap[$module]}");
					}
					
					$this->moduleMap[$module] = $class;
				}
			}
		}
		
		/**
		 * Initiate a payment using the provider registered for the request's payment module
		 * @param PaymentRequest $request
		 * @return PaymentResponse
		 */
		public function initiate(PaymentRequest $request): PaymentResponse {
			return $this->resolve($request->paymentModule)->initiate($request);
		}
		
		/**
		 * Refund a payment using the provider registered for the refund's payment module
		 * @param RefundRequest $refundRequest
		 * @return PaymentResponse
		 */
		public function refund(RefundRequest $refundRequest): PaymentResponse {
			return $this->resolve($refundRequest->paymentModule)->refund($refundRequest);
		}
		
		/**
		 * Returns payment options for the given module
		 * @param string $paymentModule
		 * @return PaymentResponse
		 */
		public function getPaymentOptions(string $paymentModule): PaymentResponse {
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
		 * Resolves a provider instance for the given module name
		 * @param string $module
		 * @return PaymentProviderInterface
		 * @throws \RuntimeException
		 */
		private function resolve(string $module): PaymentProviderInterface {
			$class = $this->moduleMap[$module] ?? throw new \RuntimeException("No payment provider registered for module '{$module}'");
			return $this->container->get($class);
		}
		
		/**
		 * Discovers all payment provider classes via composer metadata
		 * @return array
		 */
		private function discoverProviders(): array {
			$discover = new Discover();
			$discover->addScanner(new MetadataCollector("payments"));
			$discover->discover();
			
			return array_filter(
				$discover->getFamilyValues('payments', 'payment_provider'),
				[$this, 'isValidPaymentProvider']
			);
		}
		
		/**
		 * Returns true if the given value is a valid PaymentProviderInterface implementation
		 * @param mixed $value
		 * @return bool
		 */
		private function isValidPaymentProvider(mixed $value): bool {
			return
				is_string($value) &&
				class_exists($value) &&
				is_subclass_of($value, PaymentProviderInterface::class);
		}
	}