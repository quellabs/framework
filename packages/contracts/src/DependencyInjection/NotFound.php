<?php

	namespace Quellabs\Contracts\DependencyInjection;

	/**
	 * Returned by ServiceProviderInterface::createInstance() instead of null to
	 * mean, explicitly, "no record exists for this lookup" — as opposed to null,
	 * which stays available for a provider that has its own, unrelated reason to
	 * resolve a parameter to no value. Autowiring treats the two differently:
	 * a null from a provider is injected as-is, while NotFound is unwrapped to
	 * null for a nullable parameter and can be escalated (e.g. to an HTTP 404 by
	 * Canvas) for a required one. A provider that doesn't deal in "missing
	 * records" at all — a cache or logger backend, for instance — should keep
	 * using plain null; returning NotFound implies that specific meaning.
	 */
	final class NotFound {

		/**
		 * @var self|null The shared instance
		 */
		private static ?self $instance = null;

		/**
		 * Private: only instance() constructs this sentinel.
		 */
		private function __construct() {
		}

		/**
		 * Returns the shared sentinel instance, creating it on first call.
		 * @return self
		 */
		public static function instance(): self {
			return self::$instance ??= new self();
		}
	}
