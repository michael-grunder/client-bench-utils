<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

final readonly class CommandDefinition
{
    /**
     * @param \Closure(object, string, PayloadFactory, int): mixed $executor
     * @param \Closure(object, string, PayloadFactory): mixed $initializer
     */
    public function __construct(
        public string $name,
        public CommandMode $mode,
        public CommandType $type,
        public \Closure $executor,
        public \Closure $initializer,
    ) {
    }

    public function execute(object $client, string $key, PayloadFactory $payloads, int $variant): mixed
    {
        return ($this->executor)($client, $key, $payloads, $variant);
    }

    public function initialize(object $client, string $key, PayloadFactory $payloads): mixed
    {
        return ($this->initializer)($client, $key, $payloads);
    }
}
