<?php

	namespace Quellabs\Contracts\Http;

	use RuntimeException;

	/**
	 * Base exception type for HTTP-related failures, carrying an HTTP status code via getCode().
	 * Lives in quellabs/contracts (rather than a specific framework package) so integration
	 * packages can throw HTTP-semantic errors without depending on the framework implementation.
	 */
	class HttpException extends RuntimeException {
	}
