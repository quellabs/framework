<?php

	namespace Quellabs\Contracts\DependencyInjection;

	/**
	 * Marker for a ServiceProviderInterface whose null return specifically means
	 * "no record found for the requested identity" (e.g. no database row for an
	 * entity), as opposed to any other reason a provider might legitimately
	 * return null. Canvas uses this to convert a required parameter resolved to
	 * null into a deliberate HTTP 404.
	 *
	 * A provider for an ordinary service (cache, logger, etc.) must not
	 * implement this even if it can itself return null for an absent optional
	 * resource — only providers whose null means "missing record" belong here.
	 */
	interface EntityProviderInterface extends ServiceProviderInterface {
	}
