<?php
	
	namespace Quellabs\Recommender\Internal\Reconciliation;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Internal\Query\SubjectRatings;
	use Quellabs\Recommender\Internal\UserSimilarity;
	use Quellabs\Recommender\Sources\ItemLinksSource;
	use Quellabs\Recommender\Sources\SlopeOneSource;
	use Quellabs\Recommender\Sources\TopRatedSource;
	use Quellabs\Recommender\Sources\UserSimilaritySource;
	
	/** Builds the candidate sources for each request, with one ratings memo per request. */
	readonly class RequestSourcesFactory {
		
		/** @var Connection Ratings database connection */
		private Connection $connection;
		
		/** @var RecommendationConfig Recommender settings */
		private RecommendationConfig $config;
		
		/** @var UserSimilarity Neighbour lookup behind the user-similarity source */
		private UserSimilarity $similarity;
		
		/**
		 * Store the dependencies shared by every request.
		 * @param Connection $connection Ratings database connection
		 * @param RecommendationConfig $config Recommender settings
		 * @param UserSimilarity $similarity Neighbour lookup behind the user-similarity source
		 */
		public function __construct(Connection $connection, RecommendationConfig $config, UserSimilarity $similarity) {
			$this->connection = $connection;
			$this->config = $config;
			$this->similarity = $similarity;
		}
		
		/**
		 * Build the sources for one request. Keep the result for that request only, because its ratings memo never refreshes.
		 * @return RequestSources Sources sharing one ratings memo
		 */
		public function create(): RequestSources {
			$ratings = new SubjectRatings($this->connection, true);
			
			return new RequestSources($this->connection, $this->config, $ratings,
				new UserSimilaritySource($this->connection, $this->config, $this->similarity),
				new SlopeOneSource($this->connection, $this->config, $ratings),
				new ItemLinksSource($this->connection, $this->config, $ratings),
				new TopRatedSource($this->connection, $this->config, $ratings));
		}
	}
