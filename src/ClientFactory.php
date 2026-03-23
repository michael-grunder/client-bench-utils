<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

final class ClientFactory
{
    /**
     * @return object
     */
    public function create(BenchmarkConfig $config): object
    {
        return match ($config->class) {
            'redis' => $this->createRedis($config),
            'relay' => $this->createRelay($config),
            default => throw new \InvalidArgumentException(sprintf('Unsupported client class "%s".', $config->class)),
        };
    }

    private function createRedis(BenchmarkConfig $config): object
    {
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('Redis extension is not loaded.');
        }

        $client = new \Redis();
        $this->connect($client, $config);

        return $client;
    }

    private function createRelay(BenchmarkConfig $config): object
    {
        if (!class_exists(\Relay\Relay::class)) {
            throw new \RuntimeException('Relay extension is not loaded.');
        }

        $client = new \Relay\Relay();
        $this->connect($client, $config);

        return $client;
    }

    private function connect(object $client, BenchmarkConfig $config): void
    {
        $connected = $client->connect($config->host, $config->port);

        if ($connected !== true) {
            throw new \RuntimeException(sprintf('Failed to connect to Redis at %s:%d.', $config->host, $config->port));
        }

        $this->applyOptions($client, $config);
    }

    private function applyOptions(object $client, BenchmarkConfig $config): void
    {
        if ($config->prefix !== '') {
            $client->setOption($this->redisConst('OPT_PREFIX'), $config->prefix);
        }

        if ($config->serializer !== null) {
            $client->setOption($this->redisConst('OPT_SERIALIZER'), $this->serializerOption($config->serializer));
        }

        if ($config->compression !== null) {
            $client->setOption($this->redisConst('OPT_COMPRESSION'), $this->compressionOption($config->compression));
        }

        if ($config->optIgnoreNumbers) {
            $client->setOption($this->redisConst('OPT_PACK_IGNORE_NUMBERS'), true);
        }
    }

    private function serializerOption(string $serializer): int
    {
        return match ($serializer) {
            'php' => $this->redisConst('SERIALIZER_PHP'),
            'json' => $this->redisConst('SERIALIZER_JSON'),
            'igbinary' => $this->redisConst('SERIALIZER_IGBINARY'),
            'msgpack' => $this->redisConst('SERIALIZER_MSGPACK'),
            default => throw new \InvalidArgumentException(sprintf('Unsupported serializer "%s".', $serializer)),
        };
    }

    private function compressionOption(string $compression): int
    {
        return match ($compression) {
            'zstd' => $this->redisConst('COMPRESSION_ZSTD'),
            'lzf' => $this->redisConst('COMPRESSION_LZF'),
            'lz4' => $this->redisConst('COMPRESSION_LZ4'),
            default => throw new \InvalidArgumentException(sprintf('Unsupported compression "%s".', $compression)),
        };
    }

    private function redisConst(string $name): int
    {
        $constantName = sprintf('Redis::%s', $name);

        if (!defined($constantName)) {
            throw new \RuntimeException(sprintf('Required Redis constant %s is not available.', $constantName));
        }

        $value = constant($constantName);

        if (!is_int($value)) {
            throw new \RuntimeException(sprintf('Redis constant %s did not resolve to an integer.', $constantName));
        }

        return $value;
    }
}
