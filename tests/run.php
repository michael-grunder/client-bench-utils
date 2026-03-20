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
