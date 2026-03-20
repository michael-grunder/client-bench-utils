<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Mike\BenchUtils\AliasSampler;
use Mike\BenchUtils\CommandRegistry;
use Mike\BenchUtils\CommandMode;
use Mike\BenchUtils\Cli\Application;
use Mike\BenchUtils\OperationPlanner;
use Mike\BenchUtils\PayloadFactory;

$tests = [];

$assert = static function (bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tests['command groups resolve without duplicates'] = static function () use ($assert): void {
    $registry = new CommandRegistry();
    $commands = $registry->resolve('@read,@hash,get');
    $names = array_map(static fn ($command): string => $command->name, $commands);

    $assert($names === ['get', 'hget', 'hgetall', 'lrange', 'smembers', 'zrange', 'zscore', 'hset', 'hmset'], 'Unexpected resolved command order.');
};

$tests['command exclusions can subtract from the default set'] = static function () use ($assert): void {
    $registry = new CommandRegistry();
    $commands = $registry->resolve('!zrange');
    $names = array_map(static fn ($command): string => $command->name, $commands);

    $assert(!in_array('zrange', $names, true), 'Excluded command should not be present.');
    $assert(count($names) === count($registry->definitions()) - 1, 'Default exclusion should start from @all.');
};

$tests['command exclusions can subtract included groups and aliases'] = static function () use ($assert): void {
    $registry = new CommandRegistry();
    $commands = $registry->resolve('@read,~zrange,!zscore');
    $names = array_map(static fn ($command): string => $command->name, $commands);

    $assert($names === ['get', 'hget', 'hgetall', 'lrange', 'smembers'], 'Unexpected mixed include/exclude resolution.');
};

$tests['alias sampler favors heavier weight'] = static function () use ($assert): void {
    $sampler = new AliasSampler([1.0, 9.0]);
    $counts = [0, 0];

    for ($index = 0; $index < 1000; $index++) {
        $counts[$sampler->sample($index, (($index * 37) % 100) / 100)]++;
    }

    $assert($counts[1] > $counts[0] * 4, 'Alias sampler did not prefer the heavier weight.');
};

$tests['payload slices stay bounded'] = static function () use ($assert): void {
    $payloads = new PayloadFactory(8);

    $assert(strlen($payloads->string(3)) === 3, 'String payload slice length mismatch.');
    $assert(count($payloads->hash(5)) === 5, 'Hash payload slice length mismatch.');
    $assert(count($payloads->list(999)) === $payloads->maxCollectionLength(), 'List payload slice should clamp at max length.');
    $assert(count($payloads->zsetPairs(2)) === 4, 'Zset pair slice length mismatch.');
};

$tests['zrange command exposes integer range arguments'] = static function () use ($assert): void {
    $registry = new CommandRegistry();
    $payloads = new PayloadFactory(8);
    $command = $registry->resolve('zrange')[0];
    $arguments = $command->buildArguments('zset:0', $payloads, 4);

    $assert($arguments[0] === 'zset:0', 'Unexpected zrange key.');
    $assert($arguments[1] === 0, 'zrange start argument should remain an int for introspection.');
    $assert(is_int($arguments[2]), 'zrange stop argument should remain an int for introspection.');
};

$tests['help output documents debug introspection flag'] = static function () use ($assert): void {
    $reflection = new ReflectionMethod(Application::class, 'help');
    $help = $reflection->invoke(null);

    $assert(str_contains($help, '--debug-introspection'), 'Help output is missing the debug introspection flag.');
    $assert(str_contains($help, '--list-commands'), 'Help output is missing the supported commands flag.');
    $assert(str_contains($help, '!name'), 'Help output should document command exclusion syntax.');
};

$tests['supported commands output lists implemented commands'] = static function () use ($assert): void {
    $reflection = new ReflectionMethod(Application::class, 'supportedCommands');
    $output = $reflection->invoke(null);
    $registry = new CommandRegistry();

    $assert(str_starts_with($output, "Supported commands:\n"), 'Supported command output should start with a heading.');

    foreach ($registry->supportedCommandNames() as $name) {
        $assert(str_contains($output, sprintf("  %s\n", $name)), sprintf('Supported command output is missing "%s".', $name));
    }
};

$tests['planner honors read heavy temperature'] = static function () use ($assert): void {
    $registry = new CommandRegistry();
    $planner = new OperationPlanner();
    $commands = $registry->resolve('get,set');
    $operations = $planner->plan($commands, 200, 16, 0.95, 8);
    $readCount = 0;

    foreach ($operations as $operation) {
        if ($operation->command->mode === CommandMode::Read) {
            $readCount++;
        }
    }

    $assert($readCount > 140, 'Read-heavy plan did not produce enough read operations.');
};

$tests['planner uses only available mode'] = static function () use ($assert): void {
    $registry = new CommandRegistry();
    $planner = new OperationPlanner();
    $commands = $registry->resolve('get,hget');
    $operations = $planner->plan($commands, 20, 4, 0.0, 4);

    foreach ($operations as $operation) {
        $assert($operation->command->mode === CommandMode::Read, 'Planner selected a write command for a read-only workload.');
    }
};

$tests['summary prints runtime client class'] = static function () use ($assert): void {
    $runner = new Mike\BenchUtils\BenchmarkRunner();
    $reflection = new ReflectionMethod(Mike\BenchUtils\BenchmarkRunner::class, 'printSummary');
    $output = captureOutput(static fn () => $reflection->invoke($runner, 10, 1.5, ['get' => 10], 'Redis'));

    $assert(str_contains($output, "Client class: Redis\n"), 'Summary should include the runtime client class.');
};

$tests['relay summary prints cache and memory stats'] = static function () use ($assert): void {
    $runner = new Mike\BenchUtils\BenchmarkRunner();
    $reflection = new ReflectionMethod(Mike\BenchUtils\BenchmarkRunner::class, 'formatRelayStatsSummary');
    [$cacheLine, $memoryLine] = $reflection->invoke($runner, [
        'stats' => [
            'hits' => 1234,
            'requests' => 5000,
        ],
        'memory' => [
            'used' => 123456,
            'total' => 16776243,
        ],
    ]);

    $assert($cacheLine === "Cache:       1,234 hits / 5,000 reqs\n", 'Relay summary should include formatted cache stats.');
    $assert($memoryLine === "Memory:      123,456 / 16,776,243\n", 'Relay summary should include formatted memory stats.');
};

$tests['relay summary fails fast on missing stats fields'] = static function () use ($assert): void {
    $runner = new Mike\BenchUtils\BenchmarkRunner();
    $reflection = new ReflectionMethod(Mike\BenchUtils\BenchmarkRunner::class, 'formatRelayStatsSummary');

    try {
        $reflection->invoke($runner, [
        'stats' => [
            'hits' => 1234,
        ],
        'memory' => [
            'used' => 123456,
            'total' => 16776243,
        ],
    ]);
        $assert(false, 'Missing Relay stats fields should raise an error.');
    } catch (RuntimeException $exception) {
        $assert(str_contains($exception->getMessage(), 'Relay stat "stats.requests" is missing or invalid.'), 'Unexpected Relay stats validation error.');
    }
};

/**
 * @param callable(): void $callback
 */
function captureOutput(callable $callback): string
{
    ob_start();

    try {
        $callback();

        return (string) ob_get_clean();
    } catch (Throwable $exception) {
        ob_end_clean();
        throw $exception;
    }
}

$failed = 0;

foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, sprintf("[ok] %s\n", $name));
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, sprintf("[fail] %s: %s\n", $name, $exception->getMessage()));
    }
}

exit($failed === 0 ? 0 : 1);
