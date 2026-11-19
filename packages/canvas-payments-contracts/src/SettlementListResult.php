<?php

	namespace Quellabs\Payments\Contracts;

	/**
	 * One page of {@see PaymentProviderInterface::listSettlements()}.
	 */
	final readonly class SettlementListResult {

		/** @var array<int, SettlementInfo> Settlements in this page */
		public array $settlements;

		/** Opaque cursor to pass as $cursor on the next call to fetch the next page; null when this was the last page */
		public ?string $nextCursor;

		/**
		 * @param array<int, SettlementInfo> $settlements Settlements in this page.
		 * @param ?string $nextCursor Opaque cursor to pass as $cursor on the next call to fetch
		 *                            the next page; null when this was the last page.
		 */
		public function __construct(array $settlements, ?string $nextCursor = null) {
			$this->settlements = $settlements;
			$this->nextCursor = $nextCursor;
		}
	}
