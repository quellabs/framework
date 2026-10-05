<?php

namespace Quellabs\Recommender\Internal\Query;

/** SQL predicate for whether a displayed item received a timely outcome event. */
final class OutcomeSubquery {

	/**
	 * Return an EXISTS expression for an outcome within its attribution window after the impression.
	 * The outer query must alias recommender_impressions as i and recommender_impression_items as item.
	 * @param string $eventType Outcome event type, such as click or purchase
	 * @param string $asOfParam Named parameter holding the outcome cutoff
	 * @param string $windowParam Named parameter holding the attribution period in seconds
	 * @return string SQL expression
	 */
	public static function exists(string $eventType, string $asOfParam, string $windowParam): string {
		return "EXISTS (SELECT 1 FROM recommender_outcomes o WHERE o.impression_id = i.id
			AND o.item_id = item.item_id AND o.event_type = '{$eventType}'
			AND o.occurred_at >= i.shown_at AND o.occurred_at <= :{$asOfParam}
			AND o.occurred_at <= TIMESTAMPADD(SECOND, :{$windowParam}, i.shown_at))";
	}
}
