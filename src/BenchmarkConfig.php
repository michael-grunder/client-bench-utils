<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

final readonly class BenchmarkConfig
{
    public function __construct(
        public int $count,
        public int $workers,
        public int $keys,
        public string $class,
        public string $commands,
        public bool $debugIntrospection,
        public bool $pipeline,
        public bool $multi,
        public int $chunkSize,
        public ?string $serializer,
        public ?string $compression,
        public bool $optIgnoreNumbers,
        public int $maxKeySize,
        public string $prefix,
        public float $temperature,
        public string $host,
        public int $port,
    ) {
    }
}
