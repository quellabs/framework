<?php

namespace Quellabs\Recommender\Sculpt;

use DateTimeImmutable;
use Quellabs\Recommender\ClickModelTrainer;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Sculpt\ConfigurationManager;
use Quellabs\Sculpt\Contracts\CommandBase;

/** Trains a candidate model for one exact scoring partition. */
class TrainClickModelCommand extends CommandBase {
    /** @return string Command signature. */
    public function getSignature(): string { return 'recommender:train-click-model'; }

    /** @return string Short command description. */
    public function getDescription(): string { return 'Train a click model from mature displayed-item impressions'; }

    /** @return string Usage help. */
    public function getHelp(): string {
        return 'Usage: sculpt recommender:train-click-model --category=N --placement=KEY --sources=list --from=UTC --to=UTC --as-of=UTC --click-window-seconds=N [--context=KEY]';
    }

    /** @param ConfigurationManager $config Explicit cohort and partition options
     * @return int Exit status
     */
    public function execute(ConfigurationManager $config): int {
        $category = $config->get('category');
        $placement = $config->get('placement');
        $rawSources = $config->get('sources');
        $window = $config->get('click-window-seconds');
        if ((!is_int($category) && !is_string($category))
            || (!is_int($window) && !is_string($window))
            || !ctype_digit((string)$category) || !is_string($placement) || !is_string($rawSources)
            || !ctype_digit((string)$window) || (int)$window < 1) {
            throw new \InvalidArgumentException('Category, placement, sources, and positive click window are required.');
        }
        $sources = [];
        foreach (explode(',', $rawSources) as $value) {
            $source = RecommendationSource::tryFrom($value);
            if ($source === null || in_array($source, $sources, true)) {
                throw new \InvalidArgumentException('Unknown or duplicate source.');
            }
            $sources[] = $source;
        }
        $parse = static function (mixed $raw): DateTimeImmutable {
            if (!is_string($raw) || preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',
                $raw) !== 1) {
                throw new \InvalidArgumentException('Training timestamps require ISO-8601 with an explicit UTC offset.');
            }
            try {
                $time = new DateTimeImmutable($raw);
            } catch (\Exception $exception) {
                throw new \InvalidArgumentException('Invalid training timestamp.', previous: $exception);
            }
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new \InvalidArgumentException('Invalid training timestamp.');
            }
            return $time;
        };
        /** @var RecommenderProvider $provider */
        $provider = $this->provider;
        $context = $config->get('context');
        if ($context !== null && !is_string($context)) {
            throw new \InvalidArgumentException('Context must be a string.');
        }
        $id = (new ClickModelTrainer($provider->getConnection()))->train((int)$category, $placement,
            $sources, $parse($config->get('from')), $parse($config->get('to')),
            $parse($config->get('as-of')), (int)$window, $context);
        $row = $provider->getConnection()->execute('SELECT artifact, status FROM recommender_models
            WHERE id = UNHEX(?)', [$id])->fetchAssoc();
        $artifact = json_decode((string)$row['artifact'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($artifact)) {
            throw new \UnexpectedValueException('Stored model artifact is invalid.');
        }
        $this->output->success("Created model candidate {$id} ({$row['status']}).");
        $counts = [];
        foreach (['training_items', 'training_clicks', 'holdout_items', 'holdout_clicks'] as $key) {
            if (!isset($artifact[$key]) || !is_numeric($artifact[$key])) {
                throw new \UnexpectedValueException('Stored model sample counts are invalid.');
            }
            $counts[$key] = (int)$artifact[$key];
        }
        $this->output->writeLn('Training items: ' . $counts['training_items']
            . ', clicks: ' . $counts['training_clicks']
            . '; holdout items: ' . $counts['holdout_items']
            . ', clicks: ' . $counts['holdout_clicks']);
        foreach (['baseline_metrics' => 'Baseline', 'model_metrics' => 'Model'] as $key => $label) {
            $metrics = $artifact[$key];
            if (!is_array($metrics) || !isset($metrics['log_loss'], $metrics['brier'], $metrics['ece'])
                || !is_numeric($metrics['log_loss']) || !is_numeric($metrics['brier'])
                || !is_numeric($metrics['ece'])) {
                throw new \UnexpectedValueException('Stored model metrics are invalid.');
            }
            $this->output->writeLn($label . ' log loss: ' . (float)$metrics['log_loss']
                . ', Brier: ' . (float)$metrics['brier'] . ', ECE: ' . (float)$metrics['ece']);
        }
        $modelMetrics = $artifact['model_metrics'];
        if (!is_array($modelMetrics) || !isset($modelMetrics['bins']) || !is_array($modelMetrics['bins'])) {
            throw new \UnexpectedValueException('Stored model calibration bins are invalid.');
        }
        $this->output->writeLn('Calibration bins: ' . json_encode($modelMetrics['bins'], JSON_THROW_ON_ERROR));
        return 0;
    }
}
