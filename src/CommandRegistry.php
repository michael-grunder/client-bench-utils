<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

final class CommandRegistry
{
    /**
     * @var array<string, list<string>>
     */
    private const GROUPS = [
        '@all' => [
            'get', 'set', 'incr', 'decr',
            'hset', 'hmset', 'hget', 'hgetall',
            'lpush', 'lrange',
            'sadd', 'smembers',
            'zadd', 'zrange', 'zscore',
        ],
        '@read' => ['get', 'hget', 'hgetall', 'lrange', 'smembers', 'zrange', 'zscore'],
        '@write' => ['set', 'incr', 'decr', 'hset', 'hmset', 'lpush', 'sadd', 'zadd'],
        '@string' => ['get', 'set'],
        '@hash' => ['hset', 'hmset', 'hget', 'hgetall'],
        '@list' => ['lpush', 'lrange'],
        '@set' => ['sadd', 'smembers'],
        '@zset' => ['zadd', 'zrange', 'zscore'],
        '@numeric' => ['incr', 'decr'],
    ];

    /**
     * @return array<string, CommandDefinition>
     */
    public function definitions(): array
    {
        return [
            'get' => new CommandDefinition(
                'get',
                CommandMode::Read,
                CommandType::String,
                'get',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, $payloads->string($payloads->maxStringLength()))
            ),
            'set' => new CommandDefinition(
                'set',
                CommandMode::Write,
                CommandType::String,
                'set',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key, $payloads->string($variant)],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, $payloads->string($payloads->maxStringLength()))
            ),
            'incr' => new CommandDefinition(
                'incr',
                CommandMode::Write,
                CommandType::Int,
                'incr',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, 0)
            ),
            'decr' => new CommandDefinition(
                'decr',
                CommandMode::Write,
                CommandType::Int,
                'decr',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, 0)
            ),
            'hset' => new CommandDefinition(
                'hset',
                CommandMode::Write,
                CommandType::Hash,
                'hSet',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key, $payloads->hashFieldName($variant), $payloads->hashFieldValue($variant)],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->hMSet($key, $payloads->hash($payloads->maxCollectionLength()))
            ),
            'hmset' => new CommandDefinition(
                'hmset',
                CommandMode::Write,
                CommandType::Hash,
                'hMSet',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key, $payloads->hash($variant)],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->hMSet($key, $payloads->hash($payloads->maxCollectionLength()))
            ),
            'hget' => new CommandDefinition(
                'hget',
                CommandMode::Read,
                CommandType::Hash,
                'hGet',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key, $payloads->hashFieldName($variant)],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->hMSet($key, $payloads->hash($payloads->maxCollectionLength()))
            ),
            'hgetall' => new CommandDefinition(
                'hgetall',
                CommandMode::Read,
                CommandType::Hash,
                'hGetAll',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->hMSet($key, $payloads->hash($payloads->maxCollectionLength()))
            ),
            'lpush' => new CommandDefinition(
                'lpush',
                CommandMode::Write,
                CommandType::List,
                'lPush',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key, ...$payloads->list($variant)],
                static function (object $client, string $key, PayloadFactory $payloads): mixed {
                    $client->del($key);

                    return $client->rPush($key, ...$payloads->list($payloads->maxCollectionLength()));
                }
            ),
            'lrange' => new CommandDefinition(
                'lrange',
                CommandMode::Read,
                CommandType::List,
                'lRange',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key, 0, $payloads->rangeStop($variant)],
                static function (object $client, string $key, PayloadFactory $payloads): mixed {
                    $client->del($key);

                    return $client->rPush($key, ...$payloads->list($payloads->maxCollectionLength()));
                }
            ),
            'sadd' => new CommandDefinition(
                'sadd',
                CommandMode::Write,
                CommandType::Set,
                'sAdd',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key, ...$payloads->set($variant)],
                static function (object $client, string $key, PayloadFactory $payloads): mixed {
                    $client->del($key);

                    return $client->sAdd($key, ...$payloads->set($payloads->maxCollectionLength()));
                }
            ),
            'smembers' => new CommandDefinition(
                'smembers',
                CommandMode::Read,
                CommandType::Set,
                'sMembers',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key],
                static function (object $client, string $key, PayloadFactory $payloads): mixed {
                    $client->del($key);

                    return $client->sAdd($key, ...$payloads->set($payloads->maxCollectionLength()));
                }
            ),
            'zadd' => new CommandDefinition(
                'zadd',
                CommandMode::Write,
                CommandType::Zset,
                'zAdd',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key, ...$payloads->zsetPairs($variant)],
                static function (object $client, string $key, PayloadFactory $payloads): mixed {
                    $client->del($key);

                    return $client->zAdd($key, ...$payloads->zsetPairs($payloads->maxCollectionLength()));
                }
            ),
            'zrange' => new CommandDefinition(
                'zrange',
                CommandMode::Read,
                CommandType::Zset,
                'zRange',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key, 0, $payloads->rangeStop($variant)],
                static function (object $client, string $key, PayloadFactory $payloads): mixed {
                    $client->del($key);

                    return $client->zAdd($key, ...$payloads->zsetPairs($payloads->maxCollectionLength()));
                }
            ),
            'zscore' => new CommandDefinition(
                'zscore',
                CommandMode::Read,
                CommandType::Zset,
                'zScore',
                static fn (string $key, PayloadFactory $payloads, int $variant): array => [$key, $payloads->zsetMember($variant)],
                static function (object $client, string $key, PayloadFactory $payloads): mixed {
                    $client->del($key);

                    return $client->zAdd($key, ...$payloads->zsetPairs($payloads->maxCollectionLength()));
                }
            ),
        ];
    }

    /**
     * @return list<CommandDefinition>
     */
    public function resolve(string $spec): array
    {
        $tokens = array_values(array_filter(array_map('trim', explode(',', $spec)), static fn (string $token): bool => $token !== ''));
        if ($tokens === []) {
            throw new \InvalidArgumentException('The command list cannot be empty.');
        }

        $definitions = $this->definitions();
        $resolved = [];

        foreach ($tokens as $token) {
            $lower = strtolower($token);

            if (isset(self::GROUPS[$lower])) {
                foreach (self::GROUPS[$lower] as $commandName) {
                    $resolved[$commandName] = $definitions[$commandName];
                }

                continue;
            }

            if (!isset($definitions[$lower])) {
                throw new \InvalidArgumentException(sprintf('Unknown command or group "%s".', $token));
            }

            $resolved[$lower] = $definitions[$lower];
        }

        return array_values($resolved);
    }
}
