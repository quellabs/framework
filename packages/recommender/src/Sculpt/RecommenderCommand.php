<?php
	
	namespace Quellabs\Recommender\Sculpt;
	
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/** Base class for recommender Sculpt commands that need the recommender provider. */
	abstract class RecommenderCommand extends CommandBase {
	
		/**
		 * Return the recommender provider that created this command.
		 * @return RecommenderProvider The provider holding the database connection and recommender settings
		 * @throws \LogicException When the command was created with a different provider
		 */
		protected function getRecommenderProvider(): RecommenderProvider {
			if (!$this->provider instanceof RecommenderProvider) {
				throw new \LogicException('Recommender commands require a RecommenderProvider.');
			}
	
			return $this->provider;
		}
	}
