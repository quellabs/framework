<?php

namespace Quellabs\Recommender\Sculpt;

use DateTimeImmutable;
use DateTimeZone;
use Quellabs\Sculpt\ConfigurationManager;

/** Deletes explicitly selected old evaluation cohorts in bounded batches. */
class PruneEvaluationCommand extends RecommenderCommand {
	
	/**
	 * Return the command signature.
	 * @return string Command signature
	 */
	public function getSignature(): string {
		return 'recommender:prune-evaluation';
	}
	
	/**
	 * Return the short command description.
	 * @return string Short command description
	 */
	public function getDescription(): string {
		return 'Prune evaluation impressions before an explicit UTC cutoff';
	}
	
	/**
	 * Return the usage help.
	 * @return string Usage help
	 */
	public function getHelp(): string {
		return 'Usage: sculpt recommender:prune-evaluation --before=UTC-ISO-8601 [--batch-size=1000]';
	}
	
	/**
	 * Delete impressions shown before the cutoff, in batches, until none remain.
	 * @param ConfigurationManager $config CLI options
	 * @return int Exit status
	 * @throws \InvalidArgumentException When the cutoff or batch size is missing or invalid
	 * @throws \DateMalformedStringException
	 */
	public function execute(ConfigurationManager $config): int {
		$before = $config->get('before');
		$size = $config->get('batch-size') ?? '1000';
		
		if (!is_string($before) || preg_match('/(Z|[+-]\d\d:\d\d)$/', $before) !== 1
			|| !is_string($size) || !ctype_digit($size) || (int)$size < 1) {
			throw new \InvalidArgumentException('An explicit UTC cutoff and positive batch size are required.');
		}
		
		$cutoff = (new DateTimeImmutable($before))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
		
		$connection = $this->getRecommenderProvider()->getConnection();
		$deleted = 0;
		
		do {
			$ids = $connection->execute('SELECT HEX(id) AS id FROM recommender_impressions
            WHERE shown_at < ? ORDER BY shown_at, id LIMIT ' . (int)$size, [$cutoff])->fetchAll('assoc');
			
			if ($ids !== []) {
				$connection->transactional(function () use ($connection, $ids): void {
					foreach ($ids as $row) {
						$connection->execute('DELETE FROM recommender_impressions WHERE id = UNHEX(?)', [$row['id']]);
					}
				});
				$deleted += count($ids);
			}
		} while ($ids !== []);
		
		$this->output->success("Deleted {$deleted} evaluation impressions.");
		return 0;
	}
}
