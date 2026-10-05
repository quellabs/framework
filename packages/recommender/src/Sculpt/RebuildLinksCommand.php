<?php

namespace Quellabs\Recommender\Sculpt;

use Cake\Database\Connection;
use Quellabs\Sculpt\ConfigurationManager;

/** Recomputes both derived pair measures from persisted ratings. */
class RebuildLinksCommand extends RecommenderCommand {
	
	/**
	 * Return the command signature.
	 * @return string Command name
	 */
	public function getSignature(): string {
		return 'recommender:rebuild-links';
	}
	
	/**
	 * Return the command description.
	 * @return string Description
	 */
	public function getDescription(): string {
		return 'Rebuild liked and Slope One item pairs from ratings';
	}
	
	/**
	 * Return the command help text.
	 * @return string Help text
	 */
	public function getHelp(): string {
		return <<<'HELP'
<bold>Usage:</bold>
  sculpt recommender:rebuild-links [--category=<id>]

Rebuilds both derived measures regardless of direct_links and direct_slope.
Pause rating writes for the duration of the rebuild. Each category is computed
in temporary staging storage and replaced in one transaction. An omitted
category includes categories with only stale derived rows.
HELP;
	}
	
	/**
	 * Rebuild one category, or every category that has ratings or link rows when none is given.
	 * @param ConfigurationManager $config Command arguments
	 * @return int Exit code
	 * @throws \InvalidArgumentException When the category is not a nonnegative integer
	 */
	public function execute(ConfigurationManager $config): int {
		$provider = $this->getRecommenderProvider();
		$connection = $provider->getConnection();
		$rawCategory = $config->get('category');
		
		if ($rawCategory !== null && (!is_string($rawCategory) || !ctype_digit($rawCategory))) {
			throw new \InvalidArgumentException('Category must be a nonnegative integer.');
		}
		
		$category = $config->getAsIntOrNull('category');
		
		if ($category !== null && $category < 0) {
			throw new \InvalidArgumentException("Category must be nonnegative, got {$category}.");
		}
		
		if ($category === null) {
			$rows = $connection->execute('
				SELECT
					category
				FROM vogoo_ratings
				UNION
				SELECT
					category
				FROM vogoo_links
				ORDER BY category
			')->fetchAll('assoc');
			$categories = array_map('intval', array_column($rows, 'category'));
		} else {
			$categories = [$category];
		}
		
		foreach ($categories as $value) {
			$this->rebuildCategory($connection, $value, $provider->getRecommendationConfig()->getThresholdRating());
		}
		
		return 0;
	}
	
	/**
	 * Compute one category's pairs in staging storage, then replace its link rows in one transaction.
	 * @param Connection $connection Database connection
	 * @param int $category Category to rebuild
	 * @param float $threshold Like threshold
	 * @return void
	 * @throws \Exception
	 */
	private function rebuildCategory(Connection $connection, int $category, float $threshold): void {
		$connection->execute('DROP TEMPORARY TABLE IF EXISTS vogoo_links_stage');
		$connection->execute('CREATE TEMPORARY TABLE vogoo_links_stage LIKE vogoo_links');
		
		try {
			$connection->execute('INSERT INTO vogoo_links_stage
            (item_id1, item_id2, category, liked_count, slope_count, diff_slope)
            SELECT
                a.product_id,
                b.product_id,
                a.category,
                SUM(CASE WHEN a.rating >= :threshold1 AND b.rating >= :threshold2 THEN 1 ELSE 0 END),
                COUNT(*),
                SUM(b.rating - a.rating)
            FROM vogoo_ratings a
            INNER JOIN vogoo_ratings b ON b.member_id = a.member_id
                AND b.category = a.category AND b.product_id <> a.product_id AND b.rating >= 0.0
            WHERE a.category = :category AND a.rating >= 0.0
            GROUP BY a.product_id, b.product_id, a.category',
				['threshold1' => $threshold, 'threshold2' => $threshold, 'category' => $category]);
				
			$connection->transactional(function () use ($connection, $category): void {
				$connection->execute('DELETE FROM vogoo_links WHERE category = :category', ['category' => $category]);
				$connection->execute('INSERT INTO vogoo_links
                (item_id1, item_id2, category, liked_count, slope_count, diff_slope)
                SELECT
                    item_id1,
                    item_id2,
                    category,
                    liked_count,
                    slope_count,
                    diff_slope
                FROM vogoo_links_stage');
			});
			
			$count = $connection->execute('
				SELECT
					COUNT(*) AS total
				FROM vogoo_links_stage
			')->fetchAssoc()['total'];
			$this->output->success("Category {$category}: rebuilt {$count} directed pairs.");
		} finally {
			$connection->execute('DROP TEMPORARY TABLE IF EXISTS vogoo_links_stage');
		}
	}
}
