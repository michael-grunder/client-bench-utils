<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

final readonly class Operation
{
    public function __construct(
        public CommandDefinition $command,
        public int $keyIndex,
        public int $variant,
    ) {
    }
}
