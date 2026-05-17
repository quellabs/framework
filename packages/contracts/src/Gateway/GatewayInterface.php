<?php
	
	namespace Quellabs\Contracts\Gateway;
	
	/**
	 * Marker interface for HTTP gateway classes that communicate with external APIs.
	 * Defines no methods — exists solely as a type carrier for the standard response
	 * envelope returned by every gateway request method.
	 *
	 * @phpstan-type GatewayResponse array{
	 *     request: array{result: int, errorId: string, errorMessage: string},
	 *     response?: array<string, mixed>
	 * }
	 */
	interface GatewayInterface {
	}