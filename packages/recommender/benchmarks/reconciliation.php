<?php
	
	// Run only against the dedicated test database; this resets its recommender fixtures.
	require dirname(__DIR__, 3) . '/tests/bootstrap.php';
	
	use Cake\Database\Connection;
	use Psr\Log\AbstractLogger;
	use Quellabs\Recommender\ArrayEligibilityProvider;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Reconciliation\RecommendationReconciler;
use Quellabs\Recommender\Internal\UserSimilarity;
use Quellabs\Recommender\RecommendationEngine;
	use Quellabs\Recommender\RecommendationSource;
	use Quellabs\Recommender\Reconciliation\ReconciliationRequest;
	use Quellabs\Recommender\Reconciliation\ReconciliationTuning;
	
	$connection = $GLOBALS['test_connection'] ?? null;

	if (!$connection instanceof Connection) {
		throw new \RuntimeException('Benchmark requires the test connection set by tests/bootstrap.php.');
	}

	$config = new RecommendationConfig(directLinks: false, directSlope: false);
	$reconciler = new RecommendationReconciler($connection, $config,
	    new UserSimilarity($connection, $config, new RecommendationEngine($connection, $config)));
	$driver = $connection->getDriver();
	$previousLogger = $driver->getLogger();
	$logger = new class extends AbstractLogger {
	    public int $queries = 0;
	    /** @param mixed $level Log level
	     * @param string|\Stringable $message Query
	     * @param array<string, mixed> $context Query details
	     * @return void
	     */
	    public function log($level, string|\Stringable $message, array $context = []): void {
	        $this->queries++;
	    }
	};
	
	foreach (['sparse' => 4, 'dense' => 40] as $density => $ratingsPerMember) {
	    $connection->execute('TRUNCATE TABLE vogoo_links');
	    $connection->execute('TRUNCATE TABLE vogoo_ratings');
	    $rows = [];
	    $params = [];
	    for ($member = 1; $member <= 100; $member++) {
	        for ($offset = 0; $offset < $ratingsPerMember; $offset++) {
	            $item = 100 + (($member * 7 + $offset * 13) % 200);
	            $rating = 0.65 + (($member + $offset) % 8) * 0.04;
	            $rows[] = '(?, ?, 1, ?, UTC_TIMESTAMP())';
	            array_push($params, $member, $item, $rating);
	            if (count($rows) === 500) {
	                $connection->execute('INSERT INTO vogoo_ratings
	                    (member_id, product_id, category, rating, ts) VALUES ' . implode(',', $rows), $params);
	                $rows = [];
	                $params = [];
	            }
	        }
	    }
	    if ($rows !== []) {
	        $connection->execute('INSERT INTO vogoo_ratings
	            (member_id, product_id, category, rating, ts) VALUES ' . implode(',', $rows), $params);
	    }
	    foreach (['small' => range(100, 119), 'large' => range(100, 279)] as $size => $eligible) {
	        $request = new ReconciliationRequest(new ArrayEligibilityProvider($eligible),
	            [RecommendationSource::TopRated], 10, 'benchmark', tuning: new ReconciliationTuning(maxCandidateDepth: 200));
	        $times = [];
	        $queries = [];
	        $returned = [];
	        for ($sample = 0; $sample < 5; $sample++) {
	            $logger->queries = 0;
	            $driver->setLogger($logger);
	            $start = hrtime(true);
	            try {
	                $list = $reconciler->memberSlate(1, $request);
	            } finally {
	                $driver->disableQueryLogging();
	                if ($previousLogger !== null) {
	                    $driver->setLogger($previousLogger);
	                }
	            }
	            $times[] = (hrtime(true) - $start) / 1_000_000;
	            $queries[] = $logger->queries;
	            $returned[] = count($list->items);
	        }
	        sort($times);
	        echo json_encode([
	            'density' => $density,
	            'eligible' => $size,
	            'ratings' => 100 * $ratingsPerMember,
	            'eligible_ids' => count($eligible),
	            'returned' => $returned[0],
	            'queries' => $queries,
	            'median_ms' => round($times[2], 3),
	        ], JSON_THROW_ON_ERROR), PHP_EOL;
	    }
	}
