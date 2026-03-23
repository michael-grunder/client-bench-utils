# Bench Utils

Utilities for profiling Redis-compatible PHP client extensions with minimal
userland overhead.

## Command Benchmark

Run the benchmark with either:

```bash
php exec-cmds.php --keys 1000 --commands @read,@hash
```

or:

```bash
php bin/bench-command --keys 1000 --commands get,set,hgetall
```

You can also subtract commands or groups from the selected set:

```bash
php bin/bench-command --commands !zrange
php bin/bench-command --commands @read,!zrange
php bin/bench-command --commands @write,!@del
```

If a `--commands` list contains only exclusions, the benchmark starts from `@all`
and removes those entries. Both `!name` and `~name` are accepted.

String workloads include both single-key and multi-key variants such as `get`,
`mget`, `set`, and `mset`.

To print the implemented command names without running a benchmark:

```bash
php bin/bench-command --list-commands
```

The benchmark:

- resolves command groups into an explicit command set
- pre-generates keys, payload templates, and the operation plan
- primes the required keyspace before measurement
- optionally executes work in pipeline and/or MULTI batches
- keeps running when individual benchmark commands fail and reports those failures in the summary
- can print method reflection and failing call arguments with `--debug-introspection`
- reports periodic throughput updates and a final summary

When benchmarking with serializers or compression enabled, `--opt-ignore-numbers`
sets `Redis::OPT_PACK_IGNORE_NUMBERS` so integer and float values are sent
without packing. This is useful for numeric commands such as `incr` and `decr`.

Available groups include `@all`, `@read`, `@write`, `@del`, `@string`,
`@hash`, `@list`, `@set`, `@zset`, and `@numeric`.

Use `--help` for the full option list.
