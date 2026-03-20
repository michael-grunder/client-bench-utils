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

The benchmark:

- resolves command groups into an explicit command set
- pre-generates keys, payload templates, and the operation plan
- primes the required keyspace before measurement
- optionally executes work in pipeline and/or MULTI batches
- can print method reflection and failing call arguments with `--debug-introspection`
- reports periodic throughput updates and a final summary

Use `--help` for the full option list.
