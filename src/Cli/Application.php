<?php

declare(strict_types=1);

namespace Mike\BenchUtils\Cli;

use Mike\BenchUtils\BenchmarkConfig;
use Mike\BenchUtils\BenchmarkRunner;

final class Application
{
    public static function main(array $argv): int
    {
        try {
            $config = self::parseArguments($argv);
            return (new BenchmarkRunner())->run($config);
        } catch (\Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            if (str_starts_with($exception->getMessage(), '--') || str_contains($exception->getMessage(), 'command list')) {
                fwrite(STDERR, self::help());
            }

            return 1;
        }
    }

    private static function parseArguments(array $argv): BenchmarkConfig
    {
        $options = getopt('', [
            'help',
            'list-commands',
            'count:',
            'workers:',
            'keys:',
            'class:',
            'commands:',
            'debug-introspection',
            'pipeline',
            'multi',
            'chunk-size:',
            'serializer:',
            'compression:',
            'opt-ignore-numbers',
            'max-key-size:',
            'prefix:',
            'temperature:',
            'host:',
            'port:',
        ]);

        if ($options === false) {
            throw new \InvalidArgumentException('Failed to parse CLI options.');
        }

        if (array_key_exists('help', $options)) {
            echo self::help();
            exit(0);
        }

        if (array_key_exists('list-commands', $options)) {
            echo self::supportedCommands();
            exit(0);
        }

        $count = self::positiveInt($options, 'count', 10000);
        $workers = self::positiveInt($options, 'workers', 1);
        $keys = self::positiveInt($options, 'keys', 1000);
        $chunkSize = self::positiveInt($options, 'chunk-size', 1000);
        $maxKeySize = self::positiveInt($options, 'max-key-size', 16);
        $class = self::enumValue($options, 'class', ['relay', 'redis'], 'relay');
        $serializer = self::nullableEnumValue($options, 'serializer', ['php', 'json', 'igbinary', 'msgpack']);
        $compression = self::nullableEnumValue($options, 'compression', ['zstd', 'lzf', 'lz4']);
        $optIgnoreNumbers = array_key_exists('opt-ignore-numbers', $options);
        $temperature = self::floatRange($options, 'temperature', 0.5, 0.0, 1.0);
        $commands = self::stringValue($options, 'commands', '@all');
        $prefix = (string) ($options['prefix'] ?? '');
        $host = self::stringValue($options, 'host', '127.0.0.1');
        $port = self::positiveInt($options, 'port', 6379);

        return new BenchmarkConfig(
            $count,
            $workers,
            $keys,
            $class,
            $commands,
            array_key_exists('debug-introspection', $options),
            array_key_exists('pipeline', $options),
            array_key_exists('multi', $options),
            $chunkSize,
            $serializer,
            $compression,
            $optIgnoreNumbers,
            $maxKeySize,
            $prefix,
            $temperature,
            $host,
            $port,
        );
    }

    private static function positiveInt(array $options, string $name, int $default): int
    {
        if (!array_key_exists($name, $options)) {
            return $default;
        }

        $value = filter_var($options[$name], FILTER_VALIDATE_INT);

        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException(sprintf('--%s must be a positive integer.', $name));
        }

        return $value;
    }

    private static function floatRange(array $options, string $name, float $default, float $min, float $max): float
    {
        if (!array_key_exists($name, $options)) {
            return $default;
        }

        $value = filter_var($options[$name], FILTER_VALIDATE_FLOAT);

        if (!is_float($value) && !is_int($value)) {
            throw new \InvalidArgumentException(sprintf('--%s must be a floating-point number.', $name));
        }

        $floatValue = (float) $value;

        if ($floatValue < $min || $floatValue > $max) {
            throw new \InvalidArgumentException(sprintf('--%s must be between %0.1f and %0.1f.', $name, $min, $max));
        }

        return $floatValue;
    }

    /**
     * @param list<string> $allowed
     */
    private static function enumValue(array $options, string $name, array $allowed, string $default): string
    {
        if (!array_key_exists($name, $options)) {
            return $default;
        }

        $value = strtolower((string) $options[$name]);

        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('--%s must be one of: %s.', $name, implode(', ', $allowed)));
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    private static function nullableEnumValue(array $options, string $name, array $allowed): ?string
    {
        if (!array_key_exists($name, $options)) {
            return null;
        }

        return self::enumValue($options, $name, $allowed, '');
    }

    private static function stringValue(array $options, string $name, string $default): string
    {
        if (!array_key_exists($name, $options)) {
            return $default;
        }

        $value = trim((string) $options[$name]);

        if ($value === '') {
            throw new \InvalidArgumentException(sprintf('--%s cannot be empty.', $name));
        }

        return $value;
    }

    private static function help(): string
    {
        return <<<'TEXT'
Usage:
  php exec-cmds.php [options]

Options:
  --list-commands        Print supported command names and exit
  --count <int>          Total number of commands to execute. Default: 10000
  --workers <int>        Number of concurrent worker processes. Default: 1
  --keys <int>           Keyspace size per data type. Default: 1000
  --class <string>       relay or redis. Default: relay
  --commands <list>      Comma-separated commands/groups; prefix entries with !name or ~name to exclude. Default: @all
  --debug-introspection  Print client reflection and failing call arguments
  --pipeline             Enable pipelining
  --multi                Enable MULTI/EXEC batching
  --chunk-size <int>     Commands per batch when pipeline and/or multi is enabled. Default: 1000
  --serializer <string>  php, json, igbinary, msgpack
  --compression <string> zstd, lzf, lz4
  --opt-ignore-numbers   Skip packing numbers with Redis::OPT_PACK_IGNORE_NUMBERS
  --max-key-size <int>   Payload complexity knob. Default: 16
  --prefix <string>      Redis key prefix
  --temperature <float>  Read bias between 0.0 and 1.0. Default: 0.5
  --host <string>        Redis host. Default: 127.0.0.1
  --port <int>           Redis port. Default: 6379
  --help                 Show this help

Groups:
  @all @read @write @del @string @hash @list @set @zset @numeric

Examples:
  --commands @read,@hash
  --commands !zrange
  --commands @read,!zrange

TEXT;
    }

    private static function supportedCommands(): string
    {
        $names = (new \Mike\BenchUtils\CommandRegistry())->supportedCommandNames();

        return "Supported commands:\n  " . implode("\n  ", $names) . "\n";
    }
}
