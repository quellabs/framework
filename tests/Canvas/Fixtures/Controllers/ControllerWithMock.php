<?php

	namespace Quellabs\Canvas\Tests\Fixtures\Controllers;

	use Quellabs\ObjectQuel\EntityManager;
	use PHPUnit\Framework\MockObject\MockObject;

	/**
	 * Pairs a controller under test with the mocked EntityManager it was built
	 * with, so a test can configure call expectations on the mock after the
	 * controller already exists.
	 */
	class ControllerWithMock {

		private ConfigurableJsonApiController $controller;

		/** @var EntityManager&MockObject */
		private EntityManager $mockEm;

		/**
		 * Pairs the two together so a test can retrieve either one after construction.
		 * @param ConfigurableJsonApiController $controller
		 * @param EntityManager&MockObject $mockEm
		 */
		public function __construct(ConfigurableJsonApiController $controller, EntityManager $mockEm) {
			$this->controller = $controller;
			$this->mockEm = $mockEm;
		}

		/**
		 * Returns the controller under test.
		 * @return ConfigurableJsonApiController
		 */
		public function getController(): ConfigurableJsonApiController {
			return $this->controller;
		}

		/**
		 * Returns the mocked EntityManager the controller was built with.
		 * @return EntityManager&MockObject
		 */
		public function getMockEm(): EntityManager {
			return $this->mockEm;
		}
	}
