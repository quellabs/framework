<?php

namespace Quellabs\Recommender\Sculpt;

use Quellabs\Recommender\Internal\Model\ClickModelTrainer;
use Quellabs\Sculpt\ConfigurationManager;
use Quellabs\Sculpt\Contracts\CommandBase;

/** Atomically replaces the active model after validation. */
class ActivateClickModelCommand extends CommandBase {
    /** @return string Command signature. */
    public function getSignature(): string { return 'recommender:activate-click-model'; }

    /** @return string Short command description. */
    public function getDescription(): string { return 'Activate one validated click model'; }

    /** @return string Usage help. */
    public function getHelp(): string { return 'Usage: sculpt recommender:activate-click-model --id=HEX'; }

    /** @param ConfigurationManager $config Model ID option
     * @return int Exit status
     */
    public function execute(ConfigurationManager $config): int {
        /** @var RecommenderProvider $provider */
        $provider = $this->provider;
        $id = $config->get('id');
        if (!is_string($id)) {
            throw new \InvalidArgumentException('A model ID is required.');
        }
        (new ClickModelTrainer($provider->getConnection()))->activate($id);
        $this->output->success('Click model activated.');
        return 0;
    }
}
