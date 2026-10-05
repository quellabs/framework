<?php

	namespace Quellabs\Recommender\Internal\Model;

	/** A calibrated click model together with the ID of its database row. */
	readonly class ActiveModel {

		/** @var string Lowercase hex ID of the model row */
		public string $id;

		/** @var ClickModel The calibrated click model */
		public ClickModel $model;

		/**
		 * Build the active model token.
		 * @param string $id Lowercase hex ID of the model row
		 * @param ClickModel $model The calibrated click model
		 */
		public function __construct(string $id, ClickModel $model) {
			$this->id = $id;
			$this->model = $model;
		}
	}
