<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

enum CommandType: string
{
    case String = 'string';
    case Hash = 'hash';
    case List = 'list';
    case Set = 'set';
    case Zset = 'zset';
    case Int = 'int';
}
