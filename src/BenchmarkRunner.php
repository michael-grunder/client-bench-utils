<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

final class BenchmarkRunner
{
    private ClientFactory $clientFactory;
    private CommandRegistry $registry;
    private OperationPlanner $planner;

    public function __construct(
        ?ClientFactory $clientFactory = null,
        ?CommandRegistry $registry = null,
        ?OperationPlanner $planner = null,
    ) {
        $this->clientFactory = $clientFactory ?? new ClientFactory();
        $this->registry = $registry ?? new CommandRegistry();
        $this->planner = $planner ?? new OperationPlanner();
    }

    public function run(BenchmarkConfig $config): int
    {
        $commands = $this->registry->resolve($config->commands);
        $payloads = new PayloadFactory($config->maxKeySize);
        $operations = $this->planner->plan($commands, $config->count, $config->keys, $config->temperature, $config->maxKeySize);
        $keyspace = $this->buildKeyspace($commands, $config->keys);
        $client = $this->clientFactory->create($config);

        $this->primeKeyspace($client, $commands, $payloads, $keyspace);

        $startedAt = microtime(true);
        $reporter = new ProgressReporter($startedAt);
        $breakdown = array_fill_keys(array_map(static fn (CommandDefinition $command): string => $command->name, $commands), 0);
        $executed = 0;

        if ($config->pipeline || $config->multi) {
            foreach (array_chunk($operations, $config->chunkSize) as $chunk) {
                $this->executeChunk($client, $chunk, $payloads, $keyspace, $config);

                foreach ($chunk as $operation) {
                    $breakdown[$operation->command->name]++;
                }

                $executed += count($chunk);
                $reporter->maybeReport($executed);
            }
        } else {
            foreach ($operations as $operation) {
                $this->executeOperation($client, $operation, $payloads, $keyspace);
                $breakdown[$operation->command->name]++;
                $executed++;
                $reporter->maybeReport($executed);
            }
        }

        $elapsed = max(0.000001, microtime(true) - $startedAt);
        $this->printSummary($executed, $elapsed, $breakdown);

        return 0;
    }

    /**
     * @param list<CommandDefinition> $commands
     * @return array<string, list<string>>
     */
    private function buildKeyspace(array $commands, int $keys): array
    {
        $types = [];

        foreach ($commands as $command) {
            $types[$command->type->value] = $command->type;
        }

        $keyspace = [];

        foreach ($types as $type) {
            $keyspace[$type->value] = [];

            for ($index = 0; $index < $keys; $index++) {
                $keyspace[$type->value][] = sprintf('%s:%d', $type->value, $index);
            }
        }

        return $keyspace;
    }

    /**
     * @param list<CommandDefinition> $commands
     * @param array<string, list<string>> $keyspace
     */
    private function primeKeyspace(object $client, array $commands, PayloadFactory $payloads, array $keyspace): void
    {
        $initialized = [];

        foreach ($commands as $command) {
            $typeName = $command->type->value;

            if (isset($initialized[$typeName])) {
                continue;
            }

            foreach ($keyspace[$typeName] as $key) {
                $command->initialize($client, $key, $payloads);
            }

            $initialized[$typeName] = true;
        }
    }

    /**
     * @param list<Operation> $chunk
     * @param array<string, list<string>> $keyspace
     */
    private function executeChunk(object $client, array $chunk, PayloadFactory $payloads, array $keyspace, BenchmarkConfig $config): void
    {
        $target = $client;

        if ($config->pipeline) {
            $target = $target->pipeline();
        }

        if ($config->multi) {
            $target = $target->multi();
        }

        foreach ($chunk as $operation) {
            $this->executeOperation($target, $operation, $payloads, $keyspace);
        }

        $result = $target->exec();

        if ($result === false) {
            throw new \RuntimeException('Failed to execute pipeline or MULTI batch.');
        }
    }

    /**
     * @param array<string, list<string>> $keyspace
     */
    private function executeOperation(object $client, Operation $operation, PayloadFactory $payloads, array $keyspace): void
    {
        $key = $keyspace[$operation->command->type->value][$operation->keyIndex];
        $result = $operation->command->execute($client, $key, $payloads, $operation->variant);

        if ($result === false && $operation->command->name !== 'get' && $operation->command->name !== 'hget' && $operation->command->name !== 'zscore') {
            throw new \RuntimeException(sprintf('Command "%s" failed for key "%s".', $operation->command->name, $key));
        }
    }

    /**
     * @param array<string, int> $breakdown
     */
    private function printSummary(int $executed, float $elapsed, array $breakdown): void
    {
        arsort($breakdown);

        echo sprintf("Executed: %d\n", $executed);
        echo sprintf("Elapsed: %0.6f sec\n", $elapsed);
        echo sprintf("Average throughput: %0.2f ops/sec\n", $executed / $elapsed);
        echo "Breakdown:\n";

        foreach ($breakdown as $command => $count) {
            echo sprintf("  %-10s %d\n", $command, $count);
        }
    }
}
