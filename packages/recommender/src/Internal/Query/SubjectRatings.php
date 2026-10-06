<?php

namespace Quellabs\Recommender\Internal\Query;

use Cake\Database\Connection;
use Quellabs\Recommender\Subject;
use Quellabs\Recommender\SubjectKind;

/** Loads the seen ratings of a member or visitor subject, where negative values are not-interested entries. */
final class SubjectRatings {

	/** @var Connection Ratings database connection */
	private Connection $connection;

	/**
	 * Build the loader.
	 * @param Connection $connection Ratings database connection
	 */
	public function __construct(Connection $connection) {
		$this->connection = $connection;
	}

	/**
	 * Return the ratings of a subject in one category, keyed by product ID.
	 * @param Subject $subject Member or visitor subject
	 * @param int $category Resolved category
	 * @return array<int, float> Rating per product ID
	 * @throws \InvalidArgumentException When the subject is a product, which has no ratings
	 */
	public function seen(Subject $subject, int $category): array {
		if ($subject->kind === SubjectKind::Product) {
			throw new \InvalidArgumentException('Product subjects have no ratings.');
		}

		if ($subject->kind === SubjectKind::Visitor) {
			$visitor = $subject->visitor ?? throw new \LogicException('A visitor subject always has a visitor context.');
			$ratings = [];

			foreach ($visitor->ratings($category) as $rating) {
				$ratings[$rating->productId] = $rating->rating;
			}

			return $ratings;
		}

		$rows = $this->connection->execute('
			SELECT
				product_id,
				rating
			FROM vogoo_ratings
			WHERE member_id = :member AND
			      category = :category
		', [
			'member'   => $subject->id,
			'category' => $category,
		])->fetchAll('assoc');

		$ratings = [];

		foreach ($rows as $row) {
			$ratings[(int)$row['product_id']] = (float)$row['rating'];
		}

		return $ratings;
	}
}
