# 06 — Self-Hosting Stages

Bootstrap is staged. Each stage is a working compiler.

## Definitions

- **Stage 0**: the current Rust-only compiler. Today's `target/release/manticore`.
- **Stage 1**: Rust runtime + Rust codegen + PHP frontend (parser, typeck, IR lowering). The frontend is invoked through the Rust driver.
- **Stage 2**: Rust runtime + PHP frontend + PHP codegen (textual LLVM IR emitter; `llc` does the rest).
- **Stage 3**: Slim Rust runtime + all-PHP compiler. External libraries consumed via FFI.
- **Stage N (steady state)**: Stage 3 compiles its own source and produces a byte-identical (modulo timestamps) binary.

Each stage's compiler is **compiled by the previous stage**. Stage 1's compiler binary is produced by Stage 0 compiling the Stage 1 PHP source. Once Stage 2 exists, Stage 1 is no longer needed except for verification.

## Exit criteria per stage

### Stage 1 → Stage 2

- PHP frontend matches Rust frontend on 100% of `tests/cases/`
- IR JSON output diff is empty across the suite
- `--frontend=php` is the default in CI
- Rust frontend code is deleted (or moved to `legacy/` for one release cycle)

### Stage 2 → Stage 3

- PHP codegen produces working binaries for 100% of `tests/cases/`
- Output binaries pass the same runtime tests as Stage 1 binaries
- `--codegen=php` is the default
- Rust codegen code is deleted

### Stage 3 → Stage N

- FFI is stable enough that all external-library extensions are PHP-via-FFI
- Runtime line count target hit (~5-10k lines Rust)
- Compiler compiles itself, output passes test suite
- A second-generation self-compile (compile output of self-compile) is byte-identical to first-generation

## Rust pieces that **never** leave Rust

These are correctness or hardware bound:

- Stack-switching primitive for fibers (`setjmp`/`longjmp` or libucontext or hand-written asm)
- LLVM exception personality function
- Memory allocator integration (per-PhpValue cell allocation policy)
- Atomic operations / memory barriers (if multi-threading later)
- Syscall wrappers that PHP cannot make safely

Everything else is a candidate for PHP.

## Risks specific to bootstrapping

- **Subtle differences in arithmetic** between Rust and PHP — particularly around int → float coercion, large-int handling, and float-to-int truncation. Mitigation: per-op golden tables in test data; both implementations consult them.
- **Compile speed cliff**: PHP-on-PHP compilation is slow. If it slows iteration to a halt, work stalls. Mitigation: aggressive caching, parallel compile of independent source files, profile early.
- **Test suite gaps**: the suite shapes what we consider correct. Expand it as needed when porting; do not rely on Rust behavior alone.

## Project hygiene during bootstrap

- Keep `main` always green for the **current** stage. Stage transitions happen on branches.
- A stage flip lands as a single PR that switches the default flag and removes the legacy implementation. This PR must show CI green for the new path.
- Tagged releases at stage boundaries. Easy rollback if a stage proves unstable in production.

## Bootstrap verification

After Stage N is reached:

1. `cargo build --release` → produce `stageN-rust-built-binary`
2. Use `stageN-rust-built-binary` to compile the PHP compiler source → `stageN-php-built-binary-1`
3. Use `stageN-php-built-binary-1` to compile the PHP compiler source again → `stageN-php-built-binary-2`
4. Compare `stageN-php-built-binary-1` vs `stageN-php-built-binary-2`. Modulo build timestamps and randomized symbol orders, they should be identical.

This is the formal definition of "self-hosting reached" for Manticore.

## What unlocks fastest

Recommended development order, optimizing for early visible progress:

1. **FFI MVP** ([01](01-ffi-design.md)) — small, unblocks everything else
2. **Pure-PHP rewrites of trivial Rust modules** ([02](02-runtime-shrink.md)) — easy wins
3. **Event loop unification** ([03](03-event-loop-unify.md)) — cleans up architecture, speeds tests
4. **Stage 1: PHP parser** ([05](05-php-compiler.md), Phase A) — visible, ports easily
5. **FFI Phase 2** — unlocks more rewrites
6. **Stage 2: PHP codegen** ([04](04-php-codegen.md)) — biggest architectural move
7. **Stage 1 finish (PHP typeck + IR)** in parallel with Stage 2
8. **Stage 3 cleanup** — delete Rust frontend, slim runtime

Each item lands as its own milestone; none requires waiting for a later item to start.

> **Closure design note.** Closures should be modelled as syntactic sugar over an internal `__invoke()` method on a synthetic class. The captured `use ($x, $y, …)` variables become properties of that class. The closure call site lowers to a virtual `$closure->__invoke(args)` dispatch. This keeps closure handling consistent with the object model, makes reflection cheap (closures are just objects), and avoids a separate runtime data structure.

> **Status (2026-05-22):** Steps 1, 2, 4, and 6 have a working spine.
> The end-to-end path **PHP source → AST → LLVM IR → native binary**
> works for an integer subset and is verified by
> `tools/test_compile_roundtrip.sh`. The PHP-side compiler covers the
> bootstrap surface needed for itself (`src/Parser/`, `src/Compile/`,
> `src/Codegen/Llvm/`), and parses all 33 files in `src/` cleanly.
> Remaining work expands the type domain (floats, strings, arrays,
> objects, refcount) and runs the existing test suite through the new
> path once the runtime ABI is wired.
