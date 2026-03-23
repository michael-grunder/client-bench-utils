<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

final class BenchmarkRunner
{
    private const FALSE_ALLOWED_COMMANDS = ['get', 'hget', 'zscore'];

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
        $clientClass = get_class($client);

        if ($config->debugIntrospection) {
            $this->printClientIntrospection($client, $commands);
        }

        $this->primeKeyspace($client, $commands, $payloads, $keyspace);

        $startedAt = microtime(true);
        $reporter = new ProgressReporter($startedAt);
        $breakdown = array_fill_keys(array_map(static fn (CommandDefinition $command): string => $command->name, $commands), 0);
        $failures = array_fill_keys(array_map(static fn (CommandDefinition $command): string => $command->name, $commands), 0);
        $executed = 0;

        if ($config->pipeline || $config->multi) {
            foreach (array_chunk($operations, $config->chunkSize) as $chunk) {
                foreach ($chunk as $operation) {
                    $breakdown[$operation->command->name]++;
                }

                $chunkFailures = $this->executeChunk($client, $chunk, $payloads, $keyspace, $config);

                foreach ($chunkFailures as $command => $count) {
                    $failures[$command] += $count;
                }

                $executed += count($chunk);
                $reporter->maybeReport($executed);
            }
        } else {
            foreach ($operations as $operation) {
                $breakdown[$operation->command->name]++;
                if (!$this->executeOperation($client, $operation, $payloads, $keyspace)) {
                    $failures[$operation->command->name]++;
                }

                $executed++;
                $reporter->maybeReport($executed);
            }
        }

        $elapsed = max(0.000001, microtime(true) - $startedAt);
        $this->printSummary($executed, $elapsed, $breakdown, $failures, $clientClass);

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
     * @return array<string, int>
     */
    private function executeChunk(object $client, array $chunk, PayloadFactory $payloads, array $keyspace, BenchmarkConfig $config): array
    {
        $target = $client;

        if ($config->pipeline) {
            $target = $target->pipeline();
        }

        if ($config->multi) {
            $target = $target->multi();
        }

        $queuedOperations = [];
        $failures = [];

        foreach ($chunk as $index => $operation) {
            if ($this->queueOperation($target, $operation, $payloads, $keyspace)) {
                $queuedOperations[] = [$index, $operation];
                continue;
            }

            $failures[$operation->command->name] = ($failures[$operation->command->name] ?? 0) + 1;
        }

        if ($queuedOperations === []) {
            return $failures;
        }

        $result = $target->exec();

        if ($result === false) {
            foreach ($queuedOperations as [, $operation]) {
                $failures[$operation->command->name] = ($failures[$operation->command->name] ?? 0) + 1;
            }

            return $failures;
        }

        if (!is_array($result)) {
            throw new \RuntimeException('Pipeline or MULTI batch returned an unexpected result.');
        }

        foreach ($queuedOperations as $position => [, $operation]) {
            $replyPresent = array_key_exists($position, $result);
            $reply = $replyPresent ? $result[$position] : null;

            if ($this->didOperationFail($operation->command, $reply, $replyPresent)) {
                $failures[$operation->command->name] = ($failures[$operation->command->name] ?? 0) + 1;
            }
        }

        return $failures;
    }

    /**
     * @param array<string, list<string>> $keyspace
     */
    private function executeOperation(object $client, Operation $operation, PayloadFactory $payloads, array $keyspace): bool
    {
        $typeKeyspace = $keyspace[$operation->command->type->value];
        $key = $typeKeyspace[$operation->keyIndex];
        $arguments = $operation->command->buildArguments($key, $payloads, $operation->variant, $typeKeyspace, $operation->keyIndex);

        try {
            $result = $client->{$operation->command->clientMethod}(...$arguments);
        } catch (\Throwable $exception) {
            $this->printOperationFailureDetails($client, $operation, $key, $arguments, $exception);

            return false;
        }

        if ($this->didOperationFail($operation->command, $result)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, list<string>> $keyspace
     */
    private function queueOperation(object $client, Operation $operation, PayloadFactory $payloads, array $keyspace): bool
    {
        $typeKeyspace = $keyspace[$operation->command->type->value];
        $key = $typeKeyspace[$operation->keyIndex];
        $arguments = $operation->command->buildArguments($key, $payloads, $operation->variant, $typeKeyspace, $operation->keyIndex);

        try {
            $client->{$operation->command->clientMethod}(...$arguments);
        } catch (\Throwable $exception) {
            $this->printOperationFailureDetails($client, $operation, $key, $arguments, $exception);

            return false;
        }

        return true;
    }

    private function didOperationFail(CommandDefinition $command, mixed $result, bool $resultPresent = true): bool
    {
        if (!$resultPresent) {
            return true;
        }

        if ($result instanceof \Throwable) {
            return true;
        }

        return $result === false && !in_array($command->name, self::FALSE_ALLOWED_COMMANDS, true);
    }

    /**
     * @param list<CommandDefinition> $commands
     */
    private function printClientIntrospection(object $client, array $commands): void
    {
        fwrite(STDERR, sprintf("Debug introspection enabled for %s\n", $client::class));

        $printed = [];

        foreach ($commands as $command) {
            if (isset($printed[$command->clientMethod])) {
                continue;
            }

            fwrite(STDERR, sprintf("  %s => %s\n", $command->name, $this->describeMethodSignature($client, $command->clientMethod)));
            $printed[$command->clientMethod] = true;
        }
    }

    /**
     * @param list<mixed> $arguments
     */
    private function printOperationFailureDetails(
        object $client,
        Operation $operation,
        string $key,
        array $arguments,
        \Throwable $exception,
    ): void {
        fwrite(STDERR, "Operation failure details:\n");
        fwrite(STDERR, sprintf("  client: %s\n", $client::class));
        fwrite(STDERR, sprintf("  command: %s\n", $operation->command->name));
        fwrite(STDERR, sprintf("  method: %s\n", $operation->command->clientMethod));
        fwrite(STDERR, sprintf("  key: %s\n", $key));
        fwrite(STDERR, sprintf("  variant: %d\n", $operation->variant));
        fwrite(STDERR, sprintf("  signature: %s\n", $this->describeMethodSignature($client, $operation->command->clientMethod)));

        foreach ($arguments as $index => $argument) {
            fwrite(STDERR, sprintf("  arg[%d]: %s\n", $index, $this->describeValue($argument)));
        }

        fwrite(STDERR, sprintf("  exception: %s\n", $exception->getMessage()));
    }

    private function describeMethodSignature(object $client, string $method): string
    {
        if (!method_exists($client, $method)) {
            return sprintf('%s::%s (missing)', $client::class, $method);
        }

        $reflection = new \ReflectionMethod($client, $method);
        $parameters = array_map(
            static function (\ReflectionParameter $parameter): string {
                $type = $parameter->getType();
                $typeName = $type instanceof \ReflectionNamedType
                    ? $type->getName()
                    : ($type instanceof \ReflectionUnionType
                        ? implode('|', array_map(static fn (\ReflectionNamedType $namedType): string => $namedType->getName(), $type->getTypes()))
                        : 'mixed');

                return sprintf('$%s: %s', $parameter->getName(), $typeName);
            },
            $reflection->getParameters(),
        );

        return sprintf('%s::%s(%s)', $client::class, $reflection->getName(), implode(', ', $parameters));
    }

    private function describeValue(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return sprintf('%s(%s)', get_debug_type($value), (string) $value);
        }

        if (is_string($value)) {
            return sprintf('string("%s")', addcslashes($value, "\0..\37\\\""));
        }

        if (is_bool($value)) {
            return sprintf('bool(%s)', $value ? 'true' : 'false');
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return sprintf('array(%d) %s', count($value), $encoded === false ? '[unencodable]' : $encoded);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return sprintf('%s(%s)', get_debug_type($value), $encoded === false ? '[unencodable]' : $encoded);
    }

    /**
     * @param array<string, int> $breakdown
     * @param array<string, int> $failures
     */
    private function printSummary(int $executed, float $elapsed, array $breakdown, array $failures, string $clientClass): void
    {
        arsort($breakdown);
        arsort($failures);
        $failed = array_sum($failures);

        echo sprintf("Executed: %d\n", $executed);
        echo sprintf("Succeeded: %d\n", $executed - $failed);
        echo sprintf("Failed: %d\n", $failed);
        echo sprintf("Elapsed: %0.6f sec\n", $elapsed);
        echo sprintf("Average throughput: %0.2f ops/sec\n", $executed / $elapsed);
        echo sprintf("Client class: %s\n", $clientClass);
        $this->printRelayStatsSummary($clientClass);
        echo "Breakdown:\n";

        foreach ($breakdown as $command => $count) {
            echo sprintf("  %-10s %d\n", $command, $count);
        }

        if ($failed === 0) {
            return;
        }

        echo "Failures:\n";

        foreach ($failures as $command => $count) {
            if ($count === 0) {
                continue;
            }

            echo sprintf("  %-10s %d\n", $command, $count);
        }
    }

    private function printRelayStatsSummary(string $clientClass): void
    {
        if ($clientClass !== \Relay\Relay::class) {
            return;
        }

        $stats = \Relay\Relay::stats();
        [$cacheLine, $memoryLine] = $this->formatRelayStatsSummary($stats);

        echo $cacheLine;
        echo $memoryLine;
    }

    /**
     * @param array<mixed> $stats
     * @return array{string, string}
     */
    private function formatRelayStatsSummary(array $stats): array
    {
        $hits = $this->readRelayStat($stats, 'stats', 'hits');
        $requests = $this->readRelayStat($stats, 'stats', 'requests');
        $memoryUsed = $this->readRelayStat($stats, 'memory', 'used');
        $memoryTotal = $this->readRelayStat($stats, 'memory', 'total');

        return [
            sprintf("Cache:       %s hits / %s reqs\n", number_format($hits), number_format($requests)),
            sprintf("Memory:      %s / %s\n", number_format($memoryUsed), number_format($memoryTotal)),
        ];
    }

    /**
     * @param array<mixed> $stats
     */
    private function readRelayStat(array $stats, string $section, string $key): int
    {
        $sectionValue = $stats[$section] ?? null;

        if (!is_array($sectionValue)) {
            throw new \RuntimeException(sprintf('Relay stats section "%s" is missing or invalid.', $section));
        }

        $value = $sectionValue[$key] ?? null;

        if (!is_int($value)) {
            throw new \RuntimeException(sprintf('Relay stat "%s.%s" is missing or invalid.', $section, $key));
        }

        return $value;
    }
}
