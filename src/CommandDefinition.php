<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

final readonly class CommandDefinition
{
    /**
     * @param \Closure(string, PayloadFactory, int, list<string>, int): list<mixed> $argumentBuilder
     * @param \Closure(object, string, PayloadFactory): mixed $initializer
     */
    public function __construct(
        public string $name,
        public CommandMode $mode,
        public CommandType $type,
        public string $clientMethod,
        public \Closure $argumentBuilder,
        public \Closure $initializer,
    ) {
    }

    public function execute(object $client, string $key, PayloadFactory $payloads, int $variant): mixed
    {
        return $client->{$this->clientMethod}(...$this->buildArguments($key, $payloads, $variant));
    }

    /**
     * @return list<mixed>
     */
    public function buildArguments(string $key, PayloadFactory $payloads, int $variant, array $keyspace = [], int $keyIndex = 0): array
    {
        return ($this->argumentBuilder)($key, $payloads, $variant, $keyspace, $keyIndex);
    }

    public function initialize(object $client, string $key, PayloadFactory $payloads): mixed
    {
        return ($this->initializer)($client, $key, $payloads);
    }
}
