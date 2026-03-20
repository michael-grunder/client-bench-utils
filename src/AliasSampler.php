<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

final class AliasSampler
{
    /**
     * @var list<float>
     */
    private array $probabilities;

    /**
     * @var list<int>
     */
    private array $aliases;

    /**
     * @param list<float|int> $weights
     */
    public function __construct(array $weights)
    {
        if ($weights === []) {
            throw new \InvalidArgumentException('AliasSampler requires at least one weight.');
        }

        $normalized = [];
        $sum = 0.0;

        foreach ($weights as $weight) {
            $value = (float) $weight;

            if ($value < 0.0) {
                throw new \InvalidArgumentException('Weights must be non-negative.');
            }

            $normalized[] = $value;
            $sum += $value;
        }

        if ($sum <= 0.0) {
            throw new \InvalidArgumentException('At least one weight must be greater than zero.');
        }

        $count = count($normalized);
        $scaled = [];
        $small = [];
        $large = [];

        foreach ($normalized as $index => $weight) {
            $scaledWeight = ($weight * $count) / $sum;
            $scaled[$index] = $scaledWeight;

            if ($scaledWeight < 1.0) {
                $small[] = $index;
            } else {
                $large[] = $index;
            }
        }

        $this->probabilities = array_fill(0, $count, 1.0);
        $this->aliases = array_keys($normalized);

        while ($small !== [] && $large !== []) {
            $smallIndex = array_pop($small);
            $largeIndex = array_pop($large);

            $this->probabilities[$smallIndex] = $scaled[$smallIndex];
            $this->aliases[$smallIndex] = $largeIndex;

            $scaled[$largeIndex] = ($scaled[$largeIndex] + $scaled[$smallIndex]) - 1.0;

            if ($scaled[$largeIndex] < 1.0) {
                $small[] = $largeIndex;
            } else {
                $large[] = $largeIndex;
            }
        }
    }

    public function sample(int $column, float $threshold): int
    {
        $index = $column % count($this->probabilities);

        return $threshold < $this->probabilities[$index] ? $index : $this->aliases[$index];
    }

    public function size(): int
    {
        return count($this->probabilities);
    }
}
