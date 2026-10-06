<?php

namespace Quellabs\Recommender\Internal\Reconciliation;

use Cake\Database\Connection;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\Internal\Query\SubjectRatings;
use Quellabs\Recommender\Sources\ItemLinksSource;
use Quellabs\Recommender\Sources\NewProductsSource;
use Quellabs\Recommender\Sources\SlopeOneSource;
use Quellabs\Recommender\Sources\TopRatedSource;
use Quellabs\Recommender\Sources\UserSimilaritySource;

/** The candidate sources of one request. They share one ratings memo, so a subject's ratings are read once. */
readonly class RequestSources {

	/** @var Connection Ratings database connection */
	private Connection $connection;

	/** @var RecommendationConfig Recommender settings */
	private RecommendationConfig $config;

	/** @var SubjectRatings Ratings memo shared by the sources of this request */
	public SubjectRatings $ratings;

	/** @var UserSimilaritySource User-similarity candidate source */
	public UserSimilaritySource $similarity;

	/** @var SlopeOneSource Slope One candidate source */
	public SlopeOneSource $slopeOne;

	/** @var ItemLinksSource Item-links candidate source */
	public ItemLinksSource $itemLinks;

	/** @var TopRatedSource Top-rated candidate source */
	public TopRatedSource $topRated;

	/**
	 * Store the sources of one request.
	 * @param Connection $connection Ratings database connection
	 * @param RecommendationConfig $config Recommender settings
	 * @param SubjectRatings $ratings Ratings memo shared by the sources
	 * @param UserSimilaritySource $similarity User-similarity candidate source
	 * @param SlopeOneSource $slopeOne Slope One candidate source
	 * @param ItemLinksSource $itemLinks Item-links candidate source
	 * @param TopRatedSource $topRated Top-rated candidate source
	 */
	public function __construct(Connection $connection, RecommendationConfig $config, SubjectRatings $ratings, UserSimilaritySource $similarity, SlopeOneSource $slopeOne, ItemLinksSource $itemLinks, TopRatedSource $topRated) {
		$this->connection = $connection;
		$this->config = $config;
		$this->ratings = $ratings;
		$this->similarity = $similarity;
		$this->slopeOne = $slopeOne;
		$this->itemLinks = $itemLinks;
		$this->topRated = $topRated;
	}

	/**
	 * Build the new-products source for one request's ordered list, sharing this request's ratings memo.
	 * @param array<int, int> $productIds Ordered new-product IDs
	 * @return NewProductsSource New-products source for the list
	 */
	public function newProducts(array $productIds): NewProductsSource {
		return new NewProductsSource($this->connection, $this->config, $productIds, $this->ratings);
	}
}
