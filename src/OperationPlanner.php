<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

final class OperationPlanner
{
    /**
     * @param list<CommandDefinition> $commands
     * @return list<Operation>
     */
    public function plan(array $commands, int $count, int $keys, float $temperature, int $maxKeySize): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('count must be at least 1.');
        }

        if ($keys < 1) {
            throw new \InvalidArgumentException('keys must be at least 1.');
        }

        if ($temperature < 0.0 || $temperature > 1.0) {
            throw new \InvalidArgumentException('temperature must be between 0.0 and 1.0.');
        }

        $reads = array_values(array_filter($commands, static fn (CommandDefinition $command): bool => $command->mode === CommandMode::Read));
        $writes = array_values(array_filter($commands, static fn (CommandDefinition $command): bool => $command->mode === CommandMode::Write));

        $modeSampler = $this->buildModeSampler($reads, $writes, $temperature);
        $readSampler = $reads === [] ? null : new AliasSampler(array_fill(0, count($reads), 1.0));
        $writeSampler = $writes === [] ? null : new AliasSampler(array_fill(0, count($writes), 1.0));

        $operations = [];

        for ($index = 0; $index < $count; $index++) {
            $command = match ($modeSampler->sample($index, $this->threshold($index))) {
                0 => $writes[$this->sampleIndex($writeSampler, $index + 7)],
                default => $reads[$this->sampleIndex($readSampler, $index + 13)],
            };

            $operations[] = new Operation(
                $command,
                $this->mixInt($index, $keys),
                $this->mixInt($index + 31, max(1, $maxKeySize * 2)) + 1,
            );
        }

        return $operations;
    }

    /**
     * @param list<CommandDefinition> $reads
     * @param list<CommandDefinition> $writes
     */
    private function buildModeSampler(array $reads, array $writes, float $temperature): AliasSampler
    {
        if ($reads === []) {
            return new AliasSampler([1.0, 0.0]);
        }

        if ($writes === []) {
            return new AliasSampler([0.0, 1.0]);
        }

        $readWeight = 0.05 + ($temperature * 0.90);
        $writeWeight = 1.0 - $readWeight;

        return new AliasSampler([$writeWeight, $readWeight]);
    }

    private function sampleIndex(?AliasSampler $sampler, int $seed): int
    {
        if ($sampler === null) {
            return 0;
        }

        return $sampler->sample($seed, $this->threshold($seed));
    }

    private function threshold(int $seed): float
    {
        $mixed = (($seed * 1103515245) + 12345) & 0x7fffffff;

        return $mixed / 0x7fffffff;
    }

    private function mixInt(int $seed, int $modulus): int
    {
        $mixed = ($seed * 2654435761) & 0xffffffff;

        return (int) ($mixed % $modulus);
    }
}
