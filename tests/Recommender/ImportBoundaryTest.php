<?php

namespace Quellabs\Recommender\Tests;

use PHPUnit\Framework\TestCase;

/** Keeps the core free of references to the evaluation namespace and click-model classes. */
class ImportBoundaryTest extends TestCase {

    /** Path prefixes under src/ that may reference evaluation code. */
    private const EXEMPT = ['Evaluation/', 'Internal/Model/', 'Integration/', 'Sculpt/', 'Internal/Persistence/EvaluationSchema.php'];

    /** Evaluation names the core must not mention. */
    private const FORBIDDEN = '/Recommender\\\\(Evaluation|Internal\\\\Model)\\\\|EvaluationSchema|ClickModel|ActiveModel/';

    /**
     * Assert that no core source file names an evaluation class.
     * @return void
     */
    public function testCoreSourceDoesNotReferenceEvaluationCode(): void {
        $root = realpath(__DIR__ . '/../../packages/recommender/src');
        $violations = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if ($this->isExempt($relative)) {
                continue;
            }

            if (preg_match(self::FORBIDDEN, (string)file_get_contents($file->getPathname())) === 1) {
                $violations[] = $relative;
            }
        }

        $this->assertSame([], $violations, 'Core files reference evaluation code.');
    }

    /**
     * Check whether a source path belongs to the evaluation side or to the integration layer.
     * @param string $relative Path relative to src, with forward slashes
     * @return bool Whether the file may reference evaluation code
     */
    private function isExempt(string $relative): bool {
        foreach (self::EXEMPT as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
