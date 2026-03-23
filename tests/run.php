<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (!class_exists('Redis', false)) {
    class Redis
    {
        public const OPT_PREFIX = 1;
        public const OPT_SERIALIZER = 2;
        public const OPT_COMPRESSION = 3;
        public const OPT_PACK_IGNORE_NUMBERS = 4;
        public const SERIALIZER_PHP = 10;
        public const SERIALIZER_JSON = 11;
        public const SERIALIZER_IGBINARY = 12;
        public const SERIALIZER_MSGPACK = 13;
        public const COMPRESSION_ZSTD = 20;
        public const COMPRESSION_LZF = 21;
        public const COMPRESSION_LZ4 = 22;
    }
}

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

    $assert($names === ['get', 'mget', 'strlen', 'exists', 'hget', 'hgetall', 'lrange', 'llen', 'smembers', 'sismember', 'smismember', 'scard', 'zrange', 'zscore', 'zcard', 'hset', 'hmset'], 'Unexpected resolved command order.');
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

    $assert($names === ['get', 'mget', 'strlen', 'exists', 'hget', 'hgetall', 'lrange', 'llen', 'smembers', 'sismember', 'smismember', 'scard', 'zcard'], 'Unexpected mixed include/exclude resolution.');
};

$tests['delete group resolves both delete commands'] = static function () use ($assert): void {
    $registry = new CommandRegistry();
    $commands = $registry->resolve('@del');
    $names = array_map(static fn ($command): string => $command->name, $commands);

    $assert($names === ['del', 'unlink'], 'Unexpected delete group resolution.');
};

$tests['delete group can be excluded from write commands'] = static function () use ($assert): void {
    $registry = new CommandRegistry();
    $commands = $registry->resolve('@write,!@del');
    $names = array_map(static fn ($command): string => $command->name, $commands);

    $assert(!in_array('del', $names, true), 'del should be removed by @del exclusion.');
    $assert(!in_array('unlink', $names, true), 'unlink should be removed by @del exclusion.');
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

$tests['smismember command expands members as variadic arguments'] = static function () use ($assert): void {
    $registry = new CommandRegistry();
    $payloads = new PayloadFactory(8);
    $command = $registry->resolve('smismember')[0];
    $arguments = $command->buildArguments('set:0', $payloads, 3);

    $assert($arguments[0] === 'set:0', 'Unexpected smismember key.');
    $assert(count($arguments) === 4, 'smismember should pass the key plus each member as a separate argument.');
};

$tests['mget command groups contiguous keys into a single argument'] = static function () use ($assert): void {
    $registry = new CommandRegistry();
    $payloads = new PayloadFactory(8);
    $command = $registry->resolve('mget')[0];
    $keyspace = ['string:0', 'string:1', 'string:2', 'string:3'];
    $arguments = $command->buildArguments('string:2', $payloads, 3, $keyspace, 2);

    $assert(count($arguments) === 1, 'mget should pass a single key list argument.');
    $assert($arguments[0] === ['string:2', 'string:3', 'string:0'], 'mget should wrap across the string keyspace without duplicates.');
};

$tests['mset command builds a key value map for the selected keys'] = static function () use ($assert): void {
    $registry = new CommandRegistry();
    $payloads = new PayloadFactory(8);
    $command = $registry->resolve('mset')[0];
    $keyspace = ['string:0', 'string:1', 'string:2'];
    $arguments = $command->buildArguments('string:1', $payloads, 2, $keyspace, 1);

    $assert(count($arguments) === 1, 'mset should pass a single key/value map argument.');
    $assert(array_keys($arguments[0]) === ['string:1', 'string:2'], 'mset should target the selected key slice.');
    $assert($arguments[0]['string:1'] === $payloads->string(1), 'mset should assign deterministic payloads to the first key.');
    $assert($arguments[0]['string:2'] === $payloads->string(2), 'mset should assign deterministic payloads to the second key.');
};

$tests['help output documents debug introspection flag'] = static function () use ($assert): void {
    $reflection = new ReflectionMethod(Application::class, 'help');
    $help = $reflection->invoke(null);

    $assert(str_contains($help, '--debug-introspection'), 'Help output is missing the debug introspection flag.');
    $assert(str_contains($help, '--list-commands'), 'Help output is missing the supported commands flag.');
    $assert(str_contains($help, '--opt-ignore-numbers'), 'Help output is missing the numeric packing toggle.');
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
    $output = captureOutput(static fn () => $reflection->invoke($runner, 10, 1.5, ['get' => 10], ['get' => 0], 'Redis'));

    $assert(str_contains($output, "Client class: Redis\n"), 'Summary should include the runtime client class.');
    $assert(str_contains($output, "Failed: 0\n"), 'Summary should include the total failure count.');
};

$tests['summary prints failure breakdown when present'] = static function () use ($assert): void {
    $runner = new Mike\BenchUtils\BenchmarkRunner();
    $reflection = new ReflectionMethod(Mike\BenchUtils\BenchmarkRunner::class, 'printSummary');
    $output = captureOutput(static fn () => $reflection->invoke($runner, 10, 1.5, ['incr' => 6, 'get' => 4], ['incr' => 2, 'get' => 0], 'Redis'));

    $assert(str_contains($output, "Succeeded: 8\n"), 'Summary should include succeeded operations.');
    $assert(str_contains($output, "Failures:\n  incr"), 'Summary should print a non-zero failure breakdown.');
};

$tests['execute operation tracks write failures without throwing'] = static function () use ($assert): void {
    $runner = new Mike\BenchUtils\BenchmarkRunner();
    $registry = new CommandRegistry();
    $payloads = new PayloadFactory(8);
    $reflection = new ReflectionMethod(Mike\BenchUtils\BenchmarkRunner::class, 'executeOperation');
    $reflection->setAccessible(true);
    $client = new class () {
        public function incr(string $key): bool
        {
            return false;
        }
    };

    $result = $reflection->invoke(
        $runner,
        $client,
        new Mike\BenchUtils\Operation($registry->resolve('incr')[0], 0, 1),
        $payloads,
        ['int' => ['int:0']],
    );

    $assert($result === false, 'executeOperation should report write failures as false.');
};

$tests['execute chunk aggregates queued failures by command'] = static function () use ($assert): void {
    $runner = new Mike\BenchUtils\BenchmarkRunner();
    $registry = new CommandRegistry();
    $payloads = new PayloadFactory(8);
    $reflection = new ReflectionMethod(Mike\BenchUtils\BenchmarkRunner::class, 'executeChunk');
    $reflection->setAccessible(true);
    $client = new class () {
        public function pipeline(): self
        {
            return $this;
        }

        public function incr(string $key): self
        {
            return $this;
        }

        public function get(string $key): self
        {
            return $this;
        }

        public function exec(): array
        {
            return [false, false];
        }
    };

    $failures = $reflection->invoke(
        $runner,
        $client,
        [
            new Mike\BenchUtils\Operation($registry->resolve('incr')[0], 0, 1),
            new Mike\BenchUtils\Operation($registry->resolve('get')[0], 0, 1),
        ],
        $payloads,
        [
            'int' => ['int:0'],
            'string' => ['string:0'],
        ],
        new Mike\BenchUtils\BenchmarkConfig(
            2,
            1,
            'redis',
            'incr,get',
            false,
            true,
            false,
            2,
            null,
            null,
            false,
            8,
            '',
            0.5,
            '127.0.0.1',
            6379,
        ),
    );

    $assert($failures === ['incr' => 1], 'executeChunk should count failed queued replies and ignore allowed false reads.');
};

$tests['client factory enables opt-ignore-numbers'] = static function () use ($assert): void {
    $factory = new Mike\BenchUtils\ClientFactory();
    $client = new class () {
        /** @var array<int|string, mixed> */
        public array $options = [];

        public function setOption(int $option, mixed $value): void
        {
            $this->options[$option] = $value;
        }
    };
    $reflection = new ReflectionMethod(Mike\BenchUtils\ClientFactory::class, 'applyOptions');
    $reflection->setAccessible(true);

    $reflection->invoke(
        $factory,
        $client,
        new Mike\BenchUtils\BenchmarkConfig(
            1,
            1,
            'redis',
            '@all',
            false,
            false,
            false,
            1,
            null,
            null,
            true,
            1,
            '',
            0.5,
            '127.0.0.1',
            6379,
        ),
    );

    $assert(($client->options[Redis::OPT_PACK_IGNORE_NUMBERS] ?? null) === true, 'Client factory should enable OPT_PACK_IGNORE_NUMBERS when requested.');
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
