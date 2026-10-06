<?php
	
	namespace Quellabs\Recommender\Internal\Query;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Subject;
	use Quellabs\Recommender\SubjectKind;
	
	/** Loads the seen ratings of a member or visitor subject, where negative values are not-interested entries. */
	final class SubjectRatings {
		
		/** @var Connection Ratings database connection */
		private Connection $connection;
		
		/** @var bool Whether member ratings are kept for the life of this instance */
		private bool $memoize;
		
		/** @var array<string, array<int, float>> Member ratings by member and category, filled when memoizing */
		private array $memo = [];
		
		/**
		 * Build the loader.
		 * @param Connection $connection Ratings database connection
		 * @param bool $memoize Keep member ratings for the life of this instance; use only within one request
		 */
		public function __construct(Connection $connection, bool $memoize = false) {
			$this->connection = $connection;
			$this->memoize = $memoize;
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
			
			return $this->memberRatings($subject->id ?? throw new \LogicException('A member subject always has an ID.'), $category);
		}
		
		/**
		 * Return the stored ratings of a member in one category, from the memo when memoizing.
		 * @param int $member Member ID
		 * @param int $category Resolved category
		 * @return array<int, float> Rating per product ID
		 */
		private function memberRatings(int $member, int $category): array {
			if (!$this->memoize) {
				return $this->loadMember($member, $category);
			}
			
			return $this->memo["{$member}:{$category}"] ??= $this->loadMember($member, $category);
		}
		
		/**
		 * Read a member's ratings in one category from the database.
		 * @param int $member Member ID
		 * @param int $category Resolved category
		 * @return array<int, float> Rating per product ID
		 */
		private function loadMember(int $member, int $category): array {
			$rows = $this->connection->execute('
			SELECT
				product_id,
				rating
			FROM vogoo_ratings
			WHERE member_id = :member AND
			      category = :category
		', [
				'member'   => $member,
				'category' => $category,
			])->fetchAll('assoc');
			
			$ratings = [];
			
			foreach ($rows as $row) {
				$ratings[(int)$row['product_id']] = (float)$row['rating'];
			}
			
			return $ratings;
		}
	}
