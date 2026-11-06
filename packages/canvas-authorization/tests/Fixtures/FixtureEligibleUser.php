<?php

	namespace Quellabs\CanvasAuthorization\Tests\Fixtures;

	use Quellabs\CanvasAuthorization\Contracts\AccountEligibilityInterface;

	/**
	 * Minimal AccountEligibilityInterface implementation for
	 * AccountEligibilityAspectTest — AccountEligibilityAspect's constructor
	 * validates $userEntityClass via is_subclass_of(), so a real class is
	 * needed rather than a mock of the interface alone.
	 */
	class FixtureEligibleUser implements AccountEligibilityInterface {

		public function __construct(private bool $banned = false) {
		}

		public function isBanned(): bool {
			return $this->banned;
		}
	}
