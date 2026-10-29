<?php

	namespace Quellabs\Contracts\DependencyInjection;

	/**
	 * Thrown by ServiceProviderInterface::createInstance() instead of returning
	 * null to mean, explicitly, "no record exists for this lookup" — as opposed
	 * to null, which stays available for a provider that has its own, unrelated
	 * reason to resolve a parameter to no value. Autowiring catches this
	 * specifically and routes it through handleNotFound(), which a nullable
	 * parameter resolves to null and a required one (in Canvas) escalates to an
	 * HTTP 404. A provider that doesn't deal in "missing records" at all — a
	 * cache or logger backend, for instance — should keep using plain null;
	 * throwing this implies that specific meaning.
	 */
	class ResolutionNotFoundException extends \RuntimeException {
	}
