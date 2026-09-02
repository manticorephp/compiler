# Symfony Demo AOT Status

**Updated:** 2026-08-16 10:12 local time  
**Repository:** `/Users/taras/var/projects/manticore`  
**Demo fixture:** `/Users/taras/var/projects/symfony-probe`  
**Compiler branch:** `main`, `aed70ed` (`origin/main`)  
**Demo branch at recovery:** `symfony-console`, `0a20a13`

## Executive status

Symfony Demo is **not yet compilable end-to-end with the current working tree**. The front end and MIR pipeline complete for the Composer-expanded Symfony fixture, but the native compiler process crashes with `Segmentation fault: 11` (`rc=139`) during LLVM IR emission. This is a compiler-process failure, not a Symfony source diagnostic.

The previous ;s diagnostic trace is preserved in `.selfhost-reference-diagnostic-trace.log`. It shows the same class of failure during self-host pass 1: analysis succeeds, function emission reaches `Compile\\Mir\\Passes\\EmitLlvm__checkCallbackSignature`, then the compiler dies with `rc=139`.session

## Reproduction

From the demo fixture:

```bash
cd /Users/taras/var/projects/symfony-probe
MANTICORE_PRELUDE=/Users/taras/var/projects/manticore/prelude \\
  /Users/taras/var/projects/manticore/bin/manticore build manticore.json
```

Observed result:

```text
build: application 'probe' (src -> app.current)
...
Segmentation fault: 11
probe build rc=139
```

The failing build loads 193 files / 886,874 bytes, lowers to 5,371 functions, and reaches 5,450 functions after monomorphization. Code generation emits a large module: approximately 35.8 MB of function bodies and approximately 5.8 MB of preamble.

## Current blockers

| Priority | Blocker | Evidence | Consequence |
|---|---|---|---|
| P0 | Compiler segfaults while emitting a large Composer/Symfony module | `MANTICORE_STATS=1` stops after `IR: bodies 35766355 bytes`; with staging enabled it reaches `IR: deferred string globals at 0` and then dies. With `MANTICORE_NO_STREAM_IR=1` it dies immediately after the bodies marker. | No `app` binary is produced by the current compiler. |
| P1 | Native runtime parity is broken in the saved `app` artifact | `/opt/homebrew/bin/php` 8.5.8 matches `expected.txt` exactly; native diverges on `list:items -c office` and `config:set app.debug true`. | Runtime fixes remain after the compiler build is repaired. |
| P2 | Self-host fixpoint remains blocked by the same native compiler crash | `.selfhost-reference-diagnostic-trace.log` records self-host pass 1 failure with `rc=139`; active `bin/build` treats this as fatal. | Symfony progress cannot be promoted to a self-host-stable compiler until the crash is fixed. |

## PHP-vs-native parity result

The local PHP oracle is available at `/opt/homebrew/bin/php` (PHP 8.5.8). Its output is byte-identical to `expected.txt`, so the stored expected output is valid.

The saved native `app` passes `greet Taras`, `greet --yell -t 2 world`, `config:set BAD-KEY x`, and `list`. It returns success for `config:set app.debug true` but prints corrupted scientific-notation values instead of the expected `key`, `value`, and `length` labels. It fails `list:items -c office` with `Segmentation fault: 11` (`rc=139`).

## Important negative findings

The crash is not isolated to `symfony/process`. Excluding `./vendor/symfony/process` still crashes, so that package is not the sole blocker. The minimal `Process::getDefaultEnv()` fixture compiles through LLVM emission; it only fails later on the host linker because macOS lacks `libssl`. This rules out that method as the direct cause of the observed compiler segfault.

Disabling large-module streaming does not fix the failure. The compiler still dies after emitting the 35.8 MB body buffer. Therefore the first repair target is the ;s large-module / high-memory code-generation path, or memory corruption exposed by that not the Symfony command source itself.scalecompiler

## Recovery context from previous session

The working tree has uncommitted compiler changes in the following areas: built-in analysis, MIR lowering/inference/pruning, LLVM emission, string pooling, and `src/Manticore/Main.php`. It also contains several `bin/.manticore.pre-*` compiler snapshots and the preserved trace log. Do not discard these snapshots or reset the working tree before identifying which change introduced the large-module regression.

The active source already contains diagnostic and large-module controls including `MANTICORE_EMIT_TRACE`, `MANTICORE_STATS`, and `MANTICORE_NO_STREAM_IR`. These are useful for bisection and should remain available until the crash is fixed.

## Next action sequence

1. Reproduce the compiler crash under the Linux toolchain, where the Symfony probe has the intended PHP/LLVM/linker environment, and retain the full log and core/backtrace if available.
2. Bisect the uncommitted large-module changes against the `bin/.manticore.pre-*` snapshots and recent commits. First compare the point immediately after function-body emission, then the preamble/string-global staging operations.
3. Add a narrow regression fixture that creates a module near the observed scale without Composer, or reduce the Symfony source set until the first crashing threshold is known. This separates a size threshold from a particular Symfony construct.
4. After the compiler produces `app`, run all seven commands against the verified `expected.txt`. Treat the `list:items -c office` segfault and `config:set` garbage values in the saved artifact as separate runtime blockers until disproven.
5. Only after runtime parity passes, rerun self-host pass 1/2, fixpoint, and stability gates.

## Evidence files

- `.selfhost-reference-diagnostic-trace. prior self-host crash trace.log` 
- `bin/ self-host stages and fatal handling.build` 
- `src/Compile/Mir/Passes/EmitLlvm. large-module emission, staging, and deferred string globals.php` 
- `src/Compile/Mir/StringPool. deferred literal ID/value storage.php` 
- `/Users/taras/var/projects/symfony-probe/manticore. Composer fixture manifest.json` 
- `/Users/taras/var/projects/symfony-probe/run_cases. seven-command parity runner.sh` 
- `/Users/taras/var/projects/symfony-probe/expected. verified expected output.txt` 

## Backtrace  2026-08-16diagnosis 

The definitive LLDB backtrace for the original large-module crash is:

```text
_platform_strlen + 4
Compile\\Mir\\Passes\\EmitLlvm::llvmStringBytes + 204
Compile\\Mir\\Passes\\EmitLlvm::strGlobalDef + 880
Compile\\Mir\\Passes\\EmitLlvm::emitPreamble + 6100
Compile\\Mir\\Passes\\EmitLlvm::emit
```

At the fault, LLDB reports `x0 = 0` and `x1 = 0` in `_platform_strlen`. A conditional breakpoint on `strGlobalDef` catches the exact call with `x2 = 0`; the symbol argument is `@.str.4645`. This is not a linker or OS failure: a MIR string typed as `string` has a null data pointer and is passed to `strlen` while emitting the string-global preamble. The existing `$s === ''` check is not representation-safe for this native empty-string form, so it does not protect the dereference.

The root cause is therefore a **null-backed empty literal in the ;s string pool**, exposed when the large Symfony module reaches deferred/direct string-global emission. The large module increases the pool and reaches the bad entry; the module size itself is not the memory-safety defect.compiler

## Implemented alternative

The repair uses explicit metadata rather than trying to infer emptiness from a possibly invalid string value. `StringPool` now records `emptyIds` at interning time through the internal raw-string probe. Both direct and deferred string-global emitters pass that flag to `strGlobalDef(..., $empty)`. For an empty entry, `strGlobalDef` emits the canonical zero-length header and FNV value directly and never calls `llvmStringBytes`, `strlen`, or any other operation on the pooled value. Non-empty literals retain the existing byte encoder. `LowerExprs` also matches `getenv()` through the bare function name, fixing the namespace-qualified zero-argument Symfony Process call that appeared after the first crash was bypassed.

## Validation

A self-hosted test compiler containing the explicit-empty fix was built successfully. On the no-`symfony/string` reduction, which previously segfaulted, it now reaches:

```text
IR: bodies 32704709 bytes
IR: preamble 6882798 bytes
EmitLlvm fns=4119
```

The process no longer crashes in code generation; it proceeds to the linker and fails with the already separate undefined-runtime-symbol set. This validates that the `strlen(NULL)` compiler segmentation fault is fixed on the reproducible reduction. The complete 193-file Symfony build now reaches front-end verification but is currently stopped earlier by the independent `MIR.verify: dangling local $matches` error in `Symfony\\Component\\String\\AbstractUnicodeString__match`; it does not yet provide a full-module post-fix link result.

The next blockers are consequently ordered as follows: first repair the independent `MIR.verify` `$matches` lowering issue for the complete module, then resolve the undefined runtime symbols/link configuration on the reduction, and only then rerun the seven-command PHP/native parity suite and self-host fixpoint gates.
