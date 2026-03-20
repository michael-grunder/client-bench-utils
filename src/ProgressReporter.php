<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

final class ProgressReporter
{
    private float $lastReportAt;

    public function __construct(private readonly float $startedAt)
    {
        $this->lastReportAt = $startedAt;
    }

    public function maybeReport(int $executed): void
    {
        $now = microtime(true);

        if (($now - $this->lastReportAt) < 1.0) {
            return;
        }

        $elapsed = max(0.000001, $now - $this->startedAt);
        $throughput = $executed / $elapsed;

        fwrite(STDERR, sprintf(
            "[%0.2fs] executed=%d throughput=%0.2f ops/sec\n",
            $elapsed,
            $executed,
            $throughput,
        ));

        $this->lastReportAt = $now;
    }
}
