<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

enum CommandMode: string
{
    case Read = 'read';
    case Write = 'write';
}
