<?php

namespace Quellabs\Recommender\Tests;

use PHPUnit\Framework\TestCase;
use Quellabs\Recommender\Internal\Model\ClickModel;
use Quellabs\Recommender\Internal\Model\ClickModelFitter;

/** Checks deterministic standardized fitting and finite serving probabilities. */
class ClickModelFitterTest extends TestCase {
    /** @return void */
    public function testFittingProducesFiniteRepeatableProbabilities(): void {
        $training = [];
        $holdout = [];
        for ($index = 0; $index < 200; $index++) {
            $row = ['features' => ['signal.present' => (float)($index % 2),
                'log_position' => log(1 + $index % 3)], 'label' => $index % 2];
            if ($index < 160) {
                $training[] = $row;
            } else {
                $holdout[] = $row;
            }
        }
        $artifact = (new ClickModelFitter())->fit($training, $holdout);
        $model = new ClickModel($artifact);
        $this->assertGreaterThan(0.0, $model->probability(['signal.present' => 1.0], 1));
        $this->assertLessThan(1.0, $model->probability(['signal.present' => 1.0], 1));
        $this->assertSame($artifact, (new ClickModelFitter())->fit($training, $holdout));
    }

    /** @return void */
    public function testFailedHoldoutValidationDoesNotProduceActivatableArtifact(): void {
        $training = [];
        $holdout = [];
        for ($index = 0; $index < 200; $index++) {
            $signal = (float)($index % 2);
            $training[] = ['features' => ['signal.present' => $signal], 'label' => (int)$signal];
        }
        for ($index = 0; $index < 100; $index++) {
            $signal = (float)($index % 2);
            $holdout[] = ['features' => ['signal.present' => $signal], 'label' => 1 - (int)$signal];
        }
        $artifact = (new ClickModelFitter())->fit($training, $holdout);
        $this->assertFalse($artifact['validated']);
        $this->assertGreaterThan($artifact['baseline_metrics']['log_loss'],
            $artifact['model_metrics']['log_loss']);
        $this->assertGreaterThan($artifact['baseline_metrics']['brier'],
            $artifact['model_metrics']['brier']);
    }
}
