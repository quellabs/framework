<?php
	
	namespace Quellabs\Recommender\Sculpt;
	
	use DateTimeImmutable;
	use DateTimeZone;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/** Deletes explicitly selected old evaluation cohorts in bounded batches. */
	class PruneEvaluationCommand extends CommandBase {
		
		/** @return string Command signature. */
		public function getSignature(): string {
			return 'recommender:prune-evaluation';
		}
		
		/** @return string Short command description. */
		public function getDescription(): string {
			return 'Prune evaluation impressions before an explicit UTC cutoff';
		}
		
		/** @return string Usage help. */
		public function getHelp(): string {
			return 'Usage: sculpt recommender:prune-evaluation --before=UTC-ISO-8601 [--batch-size=1000]';
		}
		
		/** @param ConfigurationManager $config CLI options
		 * @return int Exit status
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
			
			/** @var RecommenderProvider $provider */
			$provider = $this->provider;
			$connection = $provider->getConnection();
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
