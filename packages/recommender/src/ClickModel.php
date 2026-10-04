<?php

namespace Quellabs\Recommender;

/** Immutable version-1 standardized logistic click model. */
readonly class ClickModel {
    /** @var array<string, float> */
    private array $coefficients;
    /** @var array<string, float> */
    private array $means;
    /** @var array<string, float> */
    private array $scales;
    private float $intercept;

    /** @param array<string, mixed> $artifact Validated stored model artifact. */
    public function __construct(public array $artifact) {
        if (($artifact['feature_schema_version'] ?? null) !== 1
            || !isset($artifact['intercept'], $artifact['coefficients'], $artifact['means'], $artifact['scales'])
            || !is_numeric($artifact['intercept']) || !is_finite((float)$artifact['intercept'])
            || !is_array($artifact['coefficients']) || !is_array($artifact['means'])
            || !is_array($artifact['scales'])) {
            throw new \UnexpectedValueException('Incompatible click model artifact.');
        }
        $coefficients = [];
        $means = [];
        $scales = [];
        foreach ($artifact['coefficients'] as $name => $coefficient) {
            if (!is_string($name) || !is_numeric($coefficient)
                || !isset($artifact['means'][$name], $artifact['scales'][$name])
                || !is_numeric($artifact['means'][$name]) || !is_numeric($artifact['scales'][$name])
                || !is_finite((float)$coefficient) || !is_finite((float)$artifact['means'][$name])
                || !is_finite((float)$artifact['scales'][$name]) || (float)$artifact['scales'][$name] <= 0) {
                throw new \UnexpectedValueException('Invalid click model coefficient.');
            }
            $coefficients[$name] = (float)$coefficient;
            $means[$name] = (float)$artifact['means'][$name];
            $scales[$name] = (float)$artifact['scales'][$name];
        }
        $this->intercept = (float)$artifact['intercept'];
        $this->coefficients = $coefficients;
        $this->means = $means;
        $this->scales = $scales;
    }

    /** @param string $json Stored model artifact
     * @return self Validated model
     */
    public static function fromJson(string $json): self {
        $artifact = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($artifact)) {
            throw new \UnexpectedValueException('Invalid click model artifact JSON.');
        }
        $fields = [];
        foreach ($artifact as $name => $value) {
            if (!is_string($name)) {
                throw new \UnexpectedValueException('Click model artifact keys must be strings.');
            }
            $fields[$name] = $value;
        }
        return new self($fields);
    }

    /** @return array<int, string> Sorted fitted feature names. */
    public function featureNames(): array {
        $names = array_keys($this->coefficients);
        sort($names);
        return $names;
    }

    /** @param array<string, float> $features Serving-time source features
     * @param int $position Actual or reference display position
     * @return float Estimated click probability
     */
    public function probability(array $features, int $position): float {
        $logOdds = $this->logOdds($features, $position);
        return $logOdds >= 0 ? 1 / (1 + exp(-$logOdds)) : exp($logOdds) / (1 + exp($logOdds));
    }

    /** @param array<string, float> $features Serving-time source features
     * @param int $position Display position
     * @return float Model log odds
     */
    public function logOdds(array $features, int $position): float {
        if ($position < 1) {
            throw new \InvalidArgumentException('Display position must be positive.');
        }
        $features['log_position'] = log($position);
        $sum = $this->intercept;
        foreach ($this->coefficients as $name => $coefficient) {
            $value = $features[$name] ?? 0.0;
            $sum += $coefficient * ($value - $this->means[$name]) / $this->scales[$name];
        }
        return $sum;
    }

    /** @param array<string, float> $features Serving-time source features
     * @return array<string, float> Source-level fitted log-odds contributions
     */
    public function sourceContributions(array $features): array {
        $result = [];
        foreach ($this->coefficients as $name => $coefficient) {
            if ($name === 'log_position') {
                continue;
            }
            $source = explode('.', $name, 2)[0];
            $result[$source] = ($result[$source] ?? 0.0)
                + $coefficient * (($features[$name] ?? 0.0) - $this->means[$name]) / $this->scales[$name];
        }
        return $result;
    }
}
