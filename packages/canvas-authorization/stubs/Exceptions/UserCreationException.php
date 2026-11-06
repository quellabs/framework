<?php

	namespace App\Exceptions;

	use Exception;

	/**
	 * Thrown when persisting a new user account fails (e.g. the database
	 * rejects the insert). Wraps the underlying OrmException so callers
	 * can show a generic "registration failed" message without leaking
	 * storage-layer details.
	 */
	class UserCreationException extends Exception {
	}
