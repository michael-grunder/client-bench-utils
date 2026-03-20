# Changelog

## Unreleased

### Added
- Added a Redis/Relay command benchmark CLI with configurable command mixes, batching, serialization, and compression options.
- Added deterministic unit tests for command resolution, workload planning, and alias-table sampling.
- Added an opt-in benchmark introspection mode that prints client method signatures and failing command arguments for extension edge cases.
- Added `--commands` exclusion selectors so benchmarks can subtract individual commands or groups with `!name` or `~name`.

### Changed
- Expanded package metadata with a CLI binary and local test script.
- Updated benchmark summaries to print the instantiated client class and include Relay cache/memory stats when running with Relay.

### Fixed

### Deprecated

### Removed
