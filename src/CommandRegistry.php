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
            'ping', 'echo', 'get', 'mget', 'set', 'mset', 'strlen', 'exists', 'del', 'unlink', 'incr', 'decr',
            'hset', 'hmset', 'hget', 'hgetall',
            'lpush', 'lrange', 'llen',
            'sadd', 'smembers', 'sismember', 'smismember', 'scard',
            'zadd', 'zrange', 'zscore', 'zcard',
        ],
        '@read' => ['ping', 'echo', 'get', 'mget', 'strlen', 'exists', 'hget', 'hgetall', 'lrange', 'llen', 'smembers', 'sismember', 'smismember', 'scard', 'zrange', 'zscore', 'zcard'],
        '@write' => ['set', 'mset', 'del', 'unlink', 'incr', 'decr', 'hset', 'hmset', 'lpush', 'sadd', 'zadd'],
        '@del' => ['del', 'unlink'],
        '@string' => ['ping', 'echo', 'get', 'mget', 'set', 'mset', 'strlen', 'exists', 'del', 'unlink'],
        '@hash' => ['hset', 'hmset', 'hget', 'hgetall'],
        '@list' => ['lpush', 'lrange', 'llen'],
        '@set' => ['sadd', 'smembers', 'sismember', 'smismember', 'scard'],
        '@zset' => ['zadd', 'zrange', 'zscore', 'zcard'],
        '@numeric' => ['incr', 'decr'],
    ];

    /**
     * @return array<string, CommandDefinition>
     */
    public function definitions(): array
    {
        return [
            'ping' => new CommandDefinition(
                'ping',
                CommandMode::Read,
                CommandType::Connection,
                'ping',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$payloads->string($variant)],
                static fn (object $client, string $key, PayloadFactory $payloads): null => null
            ),
            'echo' => new CommandDefinition(
                'echo',
                CommandMode::Read,
                CommandType::Connection,
                'echo',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$payloads->string($variant)],
                static fn (object $client, string $key, PayloadFactory $payloads): null => null
            ),
            'get' => new CommandDefinition(
                'get',
                CommandMode::Read,
                CommandType::String,
                'get',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, $payloads->string($payloads->maxStringLength()))
            ),
            'mget' => new CommandDefinition(
                'mget',
                CommandMode::Read,
                CommandType::String,
                'mGet',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [self::selectKeys($keyspace, $keyIndex, $variant)],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, $payloads->string($payloads->maxStringLength()))
            ),
            'set' => new CommandDefinition(
                'set',
                CommandMode::Write,
                CommandType::String,
                'set',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key, $payloads->string($variant)],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, $payloads->string($payloads->maxStringLength()))
            ),
            'mset' => new CommandDefinition(
                'mset',
                CommandMode::Write,
                CommandType::String,
                'mSet',
                static function (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array {
                    $keys = self::selectKeys($keyspace, $keyIndex, $variant);
                    $pairs = [];

                    foreach ($keys as $offset => $selectedKey) {
                        $pairs[$selectedKey] = $payloads->string($offset + 1);
                    }

                    return [$pairs];
                },
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, $payloads->string($payloads->maxStringLength()))
            ),
            'strlen' => new CommandDefinition(
                'strlen',
                CommandMode::Read,
                CommandType::String,
                'strLen',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, $payloads->string($payloads->maxStringLength()))
            ),
            'exists' => new CommandDefinition(
                'exists',
                CommandMode::Read,
                CommandType::String,
                'exists',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, $payloads->string($payloads->maxStringLength()))
            ),
            'del' => new CommandDefinition(
                'del',
                CommandMode::Write,
                CommandType::String,
                'del',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, $payloads->string($payloads->maxStringLength()))
            ),
            'unlink' => new CommandDefinition(
                'unlink',
                CommandMode::Write,
                CommandType::String,
                'unlink',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, $payloads->string($payloads->maxStringLength()))
            ),
            'incr' => new CommandDefinition(
                'incr',
                CommandMode::Write,
                CommandType::Int,
                'incr',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, 0)
            ),
            'decr' => new CommandDefinition(
                'decr',
                CommandMode::Write,
                CommandType::Int,
                'decr',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->set($key, 0)
            ),
            'hset' => new CommandDefinition(
                'hset',
                CommandMode::Write,
                CommandType::Hash,
                'hSet',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key, $payloads->hashFieldName($variant), $payloads->hashFieldValue($variant)],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->hMSet($key, $payloads->hash($payloads->maxCollectionLength()))
            ),
            'hmset' => new CommandDefinition(
                'hmset',
                CommandMode::Write,
                CommandType::Hash,
                'hMSet',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key, $payloads->hash($variant)],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->hMSet($key, $payloads->hash($payloads->maxCollectionLength()))
            ),
            'hget' => new CommandDefinition(
                'hget',
                CommandMode::Read,
                CommandType::Hash,
                'hGet',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key, $payloads->hashFieldName($variant)],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->hMSet($key, $payloads->hash($payloads->maxCollectionLength()))
            ),
            'hgetall' => new CommandDefinition(
                'hgetall',
                CommandMode::Read,
                CommandType::Hash,
                'hGetAll',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key],
                static fn (object $client, string $key, PayloadFactory $payloads): mixed => $client->hMSet($key, $payloads->hash($payloads->maxCollectionLength()))
            ),
            'lpush' => new CommandDefinition(
                'lpush',
                CommandMode::Write,
                CommandType::List,
                'lPush',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key, ...$payloads->list($variant)],
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
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key, 0, $payloads->rangeStop($variant)],
                static function (object $client, string $key, PayloadFactory $payloads): mixed {
                    $client->del($key);

                    return $client->rPush($key, ...$payloads->list($payloads->maxCollectionLength()));
                }
            ),
            'llen' => new CommandDefinition(
                'llen',
                CommandMode::Read,
                CommandType::List,
                'lLen',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key],
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
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key, ...$payloads->set($variant)],
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
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key],
                static function (object $client, string $key, PayloadFactory $payloads): mixed {
                    $client->del($key);

                    return $client->sAdd($key, ...$payloads->set($payloads->maxCollectionLength()));
                }
            ),
            'sismember' => new CommandDefinition(
                'sismember',
                CommandMode::Read,
                CommandType::Set,
                'sIsMember',
                static function (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array {
                    $members = $payloads->set($variant);

                    return [$key, $members[array_key_last($members)]];
                },
                static function (object $client, string $key, PayloadFactory $payloads): mixed {
                    $client->del($key);

                    return $client->sAdd($key, ...$payloads->set($payloads->maxCollectionLength()));
                }
            ),
            'smismember' => new CommandDefinition(
                'smismember',
                CommandMode::Read,
                CommandType::Set,
                'sMisMember',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key, ...$payloads->set($variant)],
                static function (object $client, string $key, PayloadFactory $payloads): mixed {
                    $client->del($key);

                    return $client->sAdd($key, ...$payloads->set($payloads->maxCollectionLength()));
                }
            ),
            'scard' => new CommandDefinition(
                'scard',
                CommandMode::Read,
                CommandType::Set,
                'sCard',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key],
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
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key, ...$payloads->zsetPairs($variant)],
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
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key, 0, $payloads->rangeStop($variant)],
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
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key, $payloads->zsetMember($variant)],
                static function (object $client, string $key, PayloadFactory $payloads): mixed {
                    $client->del($key);

                    return $client->zAdd($key, ...$payloads->zsetPairs($payloads->maxCollectionLength()));
                }
            ),
            'zcard' => new CommandDefinition(
                'zcard',
                CommandMode::Read,
                CommandType::Zset,
                'zCard',
                static fn (string $key, PayloadFactory $payloads, int $variant, array $keyspace, int $keyIndex): array => [$key],
                static function (object $client, string $key, PayloadFactory $payloads): mixed {
                    $client->del($key);

                    return $client->zAdd($key, ...$payloads->zsetPairs($payloads->maxCollectionLength()));
                }
            ),
        ];
    }

    /**
     * @param list<string> $keyspace
     * @return list<string>
     */
    private static function selectKeys(array $keyspace, int $keyIndex, int $variant): array
    {
        if ($keyspace === []) {
            throw new \InvalidArgumentException('A non-empty keyspace is required for multi-key commands.');
        }

        $count = min(count($keyspace), max(1, $variant));
        $selected = [];

        for ($offset = 0; $offset < $count; $offset++) {
            $selected[] = $keyspace[($keyIndex + $offset) % count($keyspace)];
        }

        return $selected;
    }

    /**
     * @return list<string>
     */
    public function supportedCommandNames(): array
    {
        return array_keys($this->definitions());
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
        $hasInclusiveSelector = false;

        foreach ($tokens as $token) {
            if (!$this->isExclusionToken($token)) {
                $hasInclusiveSelector = true;
                break;
            }
        }

        $resolved = $hasInclusiveSelector
            ? []
            : $this->resolveGroup('@all', $definitions);

        foreach ($tokens as $token) {
            $isExclusion = $this->isExclusionToken($token);
            $name = $isExclusion ? substr($token, 1) : $token;
            $lower = strtolower($name);

            $entries = str_starts_with($lower, '@')
                ? $this->resolveGroup($lower, $definitions, $name)
                : $this->resolveCommand($lower, $definitions, $name);

            foreach ($entries as $commandName => $definition) {
                if ($isExclusion) {
                    unset($resolved[$commandName]);
                    continue;
                }

                $resolved[$commandName] = $definition;
            }
        }

        if ($resolved === []) {
            throw new \InvalidArgumentException('The command list resolved to zero commands.');
        }

        return array_values($resolved);
    }

    private function isExclusionToken(string $token): bool
    {
        return str_starts_with($token, '!') || str_starts_with($token, '~');
    }

    /**
     * @param array<string, CommandDefinition> $definitions
     * @return array<string, CommandDefinition>
     */
    private function resolveGroup(string $group, array $definitions, ?string $originalToken = null): array
    {
        if (!isset(self::GROUPS[$group])) {
            throw new \InvalidArgumentException(sprintf('Unknown command or group "%s".', $originalToken ?? $group));
        }

        $resolved = [];

        foreach (self::GROUPS[$group] as $commandName) {
            $resolved[$commandName] = $definitions[$commandName];
        }

        return $resolved;
    }

    /**
     * @param array<string, CommandDefinition> $definitions
     * @return array<string, CommandDefinition>
     */
    private function resolveCommand(string $command, array $definitions, ?string $originalToken = null): array
    {
        if (!isset($definitions[$command])) {
            throw new \InvalidArgumentException(sprintf('Unknown command or group "%s".', $originalToken ?? $command));
        }

        return [$command => $definitions[$command]];
    }
}
