<?php

	namespace Quellabs\Canvas\Security;

	use Quellabs\Canvas\AOP\Contracts\AfterAspectInterface;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Security Headers Aspect
	 *
	 * Adds security-related HTTP headers to protect against common attacks, via
	 * a SecurityHeaderPolicyInterface: config/app.php's security_header_policy by
	 * default, or the $policyClass given here to override it for this route only.
	 * Falls back to the basic built-in SecurityHeaderPolicy if neither is set.
	 *
	 * As an AfterAspectInterface, this only runs on a matched route (including a
	 * before-aspect short circuit on that route, which AspectDispatcher still runs
	 * after aspects against). A route miss or an exception that never reaches a
	 * matched route's aspects is covered by DefaultErrorHandler instead, using the
	 * same application-wide default policy.
	 */
	class SecurityHeadersAspect implements AfterAspectInterface {

		/** @var SecurityHeaderPolicyInterface Policy applied to the response */
		private SecurityHeaderPolicyInterface $policy;

		/**
		 * Constructor
		 * @param Kernel $kernel Supplies the application-wide default policy when $policyClass is null
		 * @param string|null $policyClass Class implementing SecurityHeaderPolicyInterface to use for this route instead of the application-wide default
		 */
		public function __construct(Kernel $kernel, ?string $policyClass = null) {
			if ($policyClass !== null) {
				$policy = $kernel->getDependencyInjector()->make($policyClass);

				if (!$policy instanceof SecurityHeaderPolicyInterface) {
					throw new \InvalidArgumentException(sprintf(
						"SecurityHeadersAspect's policyClass '%s' must implement %s.",
						$policyClass,
						SecurityHeaderPolicyInterface::class
					));
				}
			} else {
				$policy = $kernel->getDefaultSecurityHeaderPolicy();
			}

			$this->policy = $policy ?? new SecurityHeaderPolicy();
		}

		/**
		 * Adds security headers to the response after the controller method executes
		 * @param MethodContextInterface $context The method execution context
		 * @param Response $response The response to modify
		 */
		public function after(MethodContextInterface $context, Response $response): void {
			$this->policy->apply($context->getRequest(), $response);
		}
	}
