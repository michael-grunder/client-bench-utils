# Changelog

## Unreleased

### Added
- Added a Redis/Relay command benchmark CLI with configurable command mixes, batching, serialization, and compression options.
- Added deterministic unit tests for command resolution, workload planning, and alias-table sampling.
- Added an opt-in benchmark introspection mode that prints client method signatures and failing command arguments for extension edge cases.
- Added `--commands` exclusion selectors so benchmarks can subtract individual commands or groups with `!name` or `~name`.
- Added a `--list-commands` utility flag to print the implemented benchmark command names without running a benchmark.
- Added `ping` and `echo` benchmark commands for connection-scoped round-trip workloads.
- Added `sismember`, `smismember`, `scard`, `strlen`, `zcard`, `llen`, `exists`, `del`, and `unlink` benchmark commands plus an `@del` selector group for explicit delete exclusions.
- Added `mget` and `mset` benchmark commands for multi-key string workloads.
- Added `--opt-ignore-numbers` to enable `Redis::OPT_PACK_IGNORE_NUMBERS` for numeric writes under serializer/compression workloads.
- Added `--workers` to run benchmark workloads concurrently with `pcntl_fork`, with each worker creating its own client connection.

### Changed
- Expanded package metadata with a CLI binary and local test script.
- Updated benchmark summaries to print the instantiated client class and include Relay cache/memory stats when running with Relay.
- Updated benchmark execution to track command failures in the final summary instead of aborting on the first failing operation.

### Fixed
- Fixed deterministic workload planning so mixed `--commands` selections no longer collapse to a single command per read/write mode.

### Deprecated

### Removed
