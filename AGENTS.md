AGENTS.md
=========

General Guidelines
------------------
- Prefer modern PHP syntax and idioms (target PHP 8.2+ or newer).
- Favor clear, modular, and reusable code over duplication unless there is a
  demonstrated performance benefit.
- Maintain a clean and coherent architecture. Avoid scattering feature-specific
  conditionals throughout the codebase; refactor when necessary.
- Keep performance in mind, especially in hot paths and frequently executed code.
- Prefer explicitness over magic. Avoid patterns that obscure control flow or
  introduce hidden behavior.

Error Handling & Safety
-----------------------
- Use defensive programming practices.
- Never ignore return values or errors unless there is a clear and justified reason.
- Validate inputs at boundaries.
- Fail fast when encountering invalid state.
- Prefer exceptions for unrecoverable errors and structured error handling for
  expected failures.

Code Style
----------
- Follow consistent formatting (PSR-12 or project-defined standard).
- Use meaningful names for variables, functions, and classes.
- Keep functions small and focused.
- Avoid deeply nested logic; prefer early returns.
- Document non-obvious behavior with concise comments.

Dependencies & Abstractions
---------------------------
- Use high quality composer packages over hand rolling functionality when appropriate.
- Avoid unnecessary abstractions.
- When wrapping external libraries, ensure the wrapper is explicit and does not
  obscure important behavior.
- Avoid dynamic dispatch patterns (e.g. `__call`) unless absolutely necessary.
- Prefer composition over inheritance where appropriate.

Performance Considerations
--------------------------
- Be mindful of allocations and unnecessary copying in hot paths.
- Avoid repeated work; cache or precompute when appropriate.
- Benchmark critical sections when making performance-sensitive changes.
- Prefer simple, predictable code over overly clever optimizations unless justified.

Testing
-------
- Write unit tests for all non-trivial logic.
- Prefer deterministic tests.
- Ensure edge cases are covered.
- Keep tests fast and independent.
- When fixing bugs, add regression tests.

Static Analysis & Linting
-------------------------
- Run static analysis (e.g. PHPStan or Psalm) and address all reported issues.
- Use linters/formatters to maintain code consistency.
- Ensure the codebase is free of warnings and errors before committing.

Build & Verification
--------------------
- Ensure the code compiles/interprets correctly after changes.
- Verify that all tests pass before finalizing changes.
- Validate behavior manually when automated coverage is insufficient.

Documentation
-------------
- Keep README and relevant documentation up to date.
- Document public APIs clearly.
- Update or add examples when behavior changes.
- Use docblocks where appropriate for tooling and clarity.

Changelog
---------
- Maintain a `CHANGELOG.md`.
- Add all changes under `## Unreleased`.
- Group entries under:
    - `### Added`
    - `### Changed`
    - `### Fixed`
    - `### Deprecated`
    - `### Removed`
- Ensure entries are clear and concise.

General Best Practices
----------------------
- Prefer simplicity and readability.
- Avoid premature optimization, but do not ignore obvious inefficiencies.
- Make incremental, well-scoped changes.
- Keep the codebase in a buildable and testable state at all times.
- When introducing significant changes, consider backward compatibility and
  migration paths.
