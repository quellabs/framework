<?php

namespace Quellabs\Recommender\Tests;

use PHPUnit\Framework\TestCase;
use Quellabs\Recommender\ClickModel;
use Quellabs\Recommender\ClickModelFitter;

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
}
