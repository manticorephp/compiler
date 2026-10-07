# AGENTS.md

Guidance for coding agents (and humans) working in this repository. `README.md`
says what Manticore is and how to use it; this file says how to change it
without breaking the one thing that makes it work: **the compiler compiles
itself**.

## What this is

Manticore is a PHP-to-native AOT compiler written in PHP. It compiles a large
subset of PHP 8.5+ to standalone arm64 / x86_64 binaries through LLVM IR, and it
compiles its own `src/` to a byte-identical fixpoint. The Zend interpreter is the
oracle: if `php` runs a program, the native binary must print the same bytes.

There is no Rust, no C, no PHP runtime in the output. `src/` is pure PHP, one class per
file, path mirrors FQN. `bin/manticore` and `lib/*.o` are build artifacts and
are gitignored.

## Build

```bash
bin/build                      # self-host rebuild via the manifest — THE normal loop
                               # (no bin/manticore: fetch the pinned release, build, rebuild)
bin/build --verify             # rebuild, then the fixpoint + suite gate
bin/build --fast               # -O1, application only, to bin/manticore.fast — NOT gate evidence
```

Iterate with `bin/build`. It uses the installed native compiler (~1–2 min); a
clean clone needs no php — `bin/build` starts from the pinned release (below).

**A failed `bin/build` poisons `bin/manticore` and `lib/*.o`.** The build refuses
to write a library `.o` that contains an undefined-function trap, but a build that
died half-way leaves the previous artifacts in an unknown state. Re-run it to a
clean finish before trusting anything.

## Bootstrap: the pinned release

Why it works this way: [`docs/design/stage0-bootstrap.md`](docs/design/stage0-bootstrap.md).

`BOOTSTRAP_VERSION` names the bootstrap compiler (stage0): the oldest release
that can build this tree. It is the one source every consumer reads:

- `bin/build` with no `bin/manticore` fetches it (`tools/fetch_bootstrap.sh`,
  checked against the release's `SHA256SUMS`), builds the tree with it, and lets
  the result rebuild itself once;
- CI warm-starts from its cache, then the published `main` image, then the pin —
  never Zend; the `bootstrap-from-pin` job builds from the pin **alone** on every
  push;
- the release workflow builds every platform from the pin only.

**The two-step rule.** A feature ships in release N; `src/` may use it only after
`BOOTSTRAP_VERSION` is raised to N.

1. Land the feature (compiler, runtime, stdlib) without using it in `src/`.
   Release N — on every platform in the matrix.
2. Raise `BOOTSTRAP_VERSION` to N in its own commit. `bootstrap-from-pin` must
   be green.
3. Only then use the feature in `src/`.

A merge that needs a newer compiler than the pin fails `bootstrap-from-pin`
(and `bin/build` from the pin) with a bootstrap-gap error naming both versions.
The cached and published compilers are newer than the pin, so the other CI rows
may stay green over the same merge — that row is the one that answers.

There is no Zend seed: `src/` is not required to run under php. php is needed
only as the `tools/difftest.sh` oracle and by a few Zend-hosted dev tools
(`tools/compile_user_mir.php`, the ~3 s fast loop).

## Test

```bash
bash tests/aot/run.sh                # AOT suite: cases/*.php + expected/*.out, auto-discovered
bash tests/aot/run.sh -k <substr>    # filter cases (do this first — the full suite is minutes)
bash tests/aot/run.sh -j 0           # all cores (note: `-j 0` is two args)
bash tests/aot/xfail.sh              # known-bug repros (tests/aot/repro): XFAIL = still open, XPASS = fixed, promote it
bash tools/difftest.sh               # byte parity vs the `php` interpreter over the corpus
bash tools/selfhost_fixpoint.sh      # gen2 IR == gen3 IR, self-host suite, rebuild stability
bash tools/install_smoke.sh          # an INSTALLED compiler (on $PATH, behind a symlink) finds its own lib/
bash tools/docker/run_tests.sh --gate [--amd64]   # the same on Linux (~2 h arm64)
```

Rules of evidence:

- **A green build proves nothing about the suite.** Run the cases.
- **Never rebuild the compiler while a suite or difftest is running.** The runner
  invokes `bin/manticore` per case; a mid-run rebuild mixes two compilers into one
  result.
- **`tools/selfhost_fixpoint.sh` replaces `bin/manticore` with a stage binary
  while it runs.** Kill it mid-way and that stub is what stays in `bin/`. Let it
  finish, or restore `bin/.manticore.prev` (the last binary `bin/build` swapped out).
- The Linux gate is not optional for anything touching `src/Runtime/`, syscalls,
  errno or the async engine — macOS and Linux diverge there.
- The `dump-*` commands do not link the stdlib, so a stdlib/FFI callee resolves as
  `unknown` and a bug in that path vanishes from the dump. Read the final binary.
- A test that passes without the fix is not a test.
- Cap every log you write (`| tail`, `head -c`). A runaway trace log has filled
  the disk more than once. Delete probe binaries and `.ll` files as soon as they
  are read.

### Adding a test

Create `tests/aot/cases/<name>.php` + `tests/aot/expected/<name>.out`. No manifest.
The expected output should be what `php` prints (`php -d xdebug.mode=off` — xdebug
poisons the oracle). Run it with `bash tests/aot/run.sh -k <name>`.

### Reporting a bug you are not fixing now

A bug found on the way to something else is **a repro plus a GitHub issue** — never
a line in a ledger, a memory note or a `TODO`. A note cannot be run, so nobody
learns when it stops being true: the first sweep of the old ledgers found 46 of
~100 "open" entries no longer reproducing.

1. Minimise it to one self-contained file: `tests/aot/repro/{wrong,crash,leak}/<name>.php`
   — `wrong` = runs, output differs; `crash` = SIGSEGV / SIGBUS / invalid IR;
   `leak` = memory php releases and the binary does not. Line 2 is a one-sentence
   comment that becomes the issue title.
2. Record the oracle beside it: `php -d xdebug.mode=off -d display_errors=stderr <name>.php > <name>.expected`.
   A leak repro measures itself — `memory_get_peak_usage()` (peak RSS natively)
   around the loop, printing `flat` under a threshold — copy `repro/leak/throw_path.php`.
   A superset feature has no oracle: write the `.expected` by hand.
3. `bash tests/aot/xfail.sh -k <name>` must say `XFAIL`. An `XPASS` means the
   repro does not show the bug; a claim without a red repro is not filed.
4. `bash tools/file_bug.sh tests/aot/repro/<kind>/<name>.php` (`-n` prints the
   body and files nothing). It refuses a repro that passes, files the issue with
   the PHP source, php's output and the actual output in the body (labels `bug` +
   `wrong-answer` / `crash` / `leak`), and writes `// issue: #N` into the repro.
   Commit the repro and its `.expected`.

Fixing one: `git mv` the repro to `tests/aot/cases/<name>.php` and its
`.expected` to `tests/aot/expected/<name>.out`, drop the `// issue:` line, and
put `Fixes #N` in the commit. `xfail.sh` exits 1 on an `XPASS` — a bug fixed as a
side effect of other work shows up there, and gets the same treatment. The gate
(`tools/docker/gate.sh`, so CI too) runs it after the suite and reports an
`XPASS` in the job summary; `MC_XFAIL=strict` makes it fail the gate.

A bug that is red on one platform only keeps its repro where it is; say which
platform in the issue and label it.

## Pipeline

```
PHP source → Lexer → Parser → AST
  → LowerFromAst → NarrowScalarGuards → ConstFold → DeadStore → InferTypes → VivifyRefArgs
  → ResolveMethodFcc → NarrowReturns → InferTypes → InlineClosures → ResolveOverloads
  → InferTypes → Monomorphize → FuseSplitJoin
  → TypeCheck (full; MANTICORE_TYPECHECK=0 → reprOnly) → NarrowReturns → CheckTypeDefs
  → ReflectAnalysis → DemoteCharLocals → InferEffects → InferAllocKind
  → ApplyMemoryMode → SpillFreshBases → InsertMemoryOps → OwnershipFlow → Verify
  → EmitLlvm (+ HoistAllocas, PruneIr) → LLVM IR → clang -c → cc → static binary
```

The driver is `cmd_compile` in `src/Manticore/Main.php`; it calls `lower_module` (the
passes) and `compile_via_mir` (EmitLlvm).
`InferTypes` re-runs after each pass that makes new types concrete. Full
annotation of the passes: `src/Compile/README.md` and `docs/design/mir.md`.

### Key files

| Area | Where |
|---|---|
| AST → MIR lowering | `src/Compile/Mir/Passes/LowerFromAst.php` (+ `Lower*.php` traits) |
| Type inference | `src/Compile/Mir/Passes/InferTypes.php` (+ `Infer*.php` traits) |
| Monomorphization / erasure | `src/Compile/Mir/Passes/Monomorphize.php` |
| LLVM codegen | `src/Compile/Mir/Passes/EmitLlvm.php` host + `EmitLlvm*.php` traits |
| Codegen builtins (inline primitives) | `src/Compile/Mir/Passes/EmitLlvmBuiltins.php` |
| Memory ABI: offsets, tags, rc encoding | `src/Compile/MemoryAbi.php` — a layout change still ships through a plain `bin/build` (see below) |
| Runtime helpers emitted as IR | `src/Compile/Runtime/*.php` |
| PHP-level stdlib | `src/Runtime/Stdlib/*.php` → `lib/manticore_stdlib.o` + `.sig` |
| Prelude (PHP injected into every program) | `prelude/*.php` |
| Static analyzer (`analyze`, compile-time warnings) | `src/Analyze/` |
| CLI + driver, manifest build, stdlib resolution | `src/Manticore/Main.php`, `src/Cli/` |
| Test harness | `tests/aot/run.sh` |
| Gates and tooling | `tools/` |

`EmitLlvm*.php`, `Lower*.php` and `Infer*.php` are **traits on one host class**.
Two consequences: a method you add is visible from every sibling trait, and PHP
type narrowing does not survive across a trait boundary — use `Walk::children`
and the existing accessors rather than re-narrowing a `Node`.

## Adding a standard function

Two tiers. Pick by what the function needs.

**PHP stdlib** — anything expressible in PHP (array / string helpers, most of
`ext/*`): add a global-namespace `function` to `src/Runtime/Stdlib/*.php`. It
compiles into `lib/manticore_stdlib.o` and is exposed to every user program via
the `.o.sig` with no registration. For a pure-PHP function, **Zend is the test
harness**: write it under a prefixed name, verify against `php` in seconds, then
rename and rebuild once.

**Codegen builtin** — a primitive, an LLVM intrinsic, a libc call, something that
must be inlined:
1. Dispatch + emitter in `EmitLlvmBuiltins.php`
   (`if ($name === '<fn>') { return $this->bi<Fn>($args); }` + the `bi<Fn>` method;
   `biAbs` / `biFloatUnary` are the templates).
2. Return type in `InferTypes::builtinReturnType`, so callers type and coerce right.

**BOOTSTRAP RULE — PHP body first, codegen builtin second.** A new codegen
builtin MUST ship with a same-named PHP body (stdlib for a public name, `prelude/`
for a name Zend owns). The compiler that rebuilds the compiler is one generation
behind and has never heard of the new builtin; an unresolved call does not fail
the build, it becomes a runtime `Call to undefined function` trap that rides into
the next binary or into `lib/*.o`. With the pair, the old compiler links the PHP
body and the new one shadows it (`emitCall` asks `emitBuiltin` before
`definedFns`). `strpos`, `array_keys`, `current`, `key` and others already work
this way. `bin/build` preflights with `bin/manticore analyze src --only
undefined.,parse.error` and refuses to write a library `.o` containing a trap.

Corollaries:
- **A memory-ABI change needs no pin raise.** The previous generation compiles
  the new source with its OWN runtime inside the compiler (which links no
  stdlib), and pass 2 rebuilds `lib/*.o` with the NEW binary, so no program ever
  mixes two layouts. Verified on the v8 → v9 bump: a v8 compiler's `bin/build`
  reached the fixpoint byte-identical to the previously seeded binary.
- **New syntax goes through the two-step rule.** It is unusable inside `src/`
  until `BOOTSTRAP_VERSION` names a release that parses it. Never ship a parser
  change together with tree code that needs it — `bootstrap-from-pin` goes red.
- A stdlib default value must not spell a NEW constant's name (same reason).
- **A prelude body of a builtin's name REPLACES the builtin** — desugar under
  another name. The reverse holds for stdlib: a strong stdlib symbol beats a
  prelude `linkonce_odr` body, so a name cannot live in both.

## Invariants that are easy to break

- **A layout has one owner.** Every offset, tag and refcount encoding comes from
  `MemoryAbi`; never hard-code one in an emitter or a runtime helper.
- **`linkonce_odr` bodies coalesce by name across modules.** Never specialize one
  from module-local information — the other module's copy may win at link time.
- **`__mir_array_retain*` is refcount-only; a value COPY must `__mir_array_adopt*`.**
  An array element read is a BORROW, not an owned value.
- **Node field order is load-bearing** in the AST/MIR node classes — append, never
  reorder, and keep `children()` in sync.
- **A bare `array` type is `KIND_UNKNOWN`** and erases its element type, including
  across a delegation hop. Write `array<int,string>` / `int[]` in docblocks.
- **When a coercion bug is found in one operator, sweep every operator sharing
  that operand path.** The last three of these each hid two siblings.
- **A checked-out binary is not its branch.** After switching branches, rebuild
  before drawing conclusions; a stale `.o` fakes a PASS and a stale binary fakes a
  FAIL.
- **Int overflow wraps** (no value-range analysis yet); `extract()` is
  unimplemented; static properties are external-linkage globals. Full gap list:
  `docs/ROADMAP.md`.

## Code style

- PHP 8.5, one class per file, path mirrors FQN.
- No comments that restate the code. Doc comments carry **array element types**
  (`/** @var array<string, Node> */`) because the compiler reads them; that is
  the one place a comment is load-bearing.
- Keep PHP signatures php-faithful: a stdlib function takes the same parameters
  in the same order as the Zend one, defaults included.
- Root cause over workaround. No reverts as a fix. If a fix needs a change in the
  compiler AND in the tree that only compiles with that fix, split it into two
  generations.
- `preg_*` is allowed anywhere in `src/`, and already used in ~25 files: every
  binary links PCRE2 regardless (see Design principles §2), so a regex costs no
  dependency a program does not already carry. Two caveats, neither about
  linkage: a character walk still beats PCRE in a HOT path compiled into every
  program (`Type::isIntKey` runs per array key and stays hand-written for that
  reason, not for portability), and `/u` over bytes that are not valid UTF-8
  makes `preg_replace` return NULL and `preg_match` false — the compiler reads
  arbitrary source bytes, so either drop `/u` or check `preg_last_error()`.
- Match the surrounding code — naming, comment density, idiom.
- Commit messages: imperative, one line of what changed and why; no generated
  co-author trailers.

## Design principles

1. **Correctness first** — match Zend semantics for the supported subset;
   `tools/difftest.sh` is the gate. Where Zend emits a warning and carries on,
   Manticore throws.
2. **No PHP runtime in the output** — a binary links libc, PCRE2 and OpenSSL,
   plus whatever an FFI binding names (`#[Library]`) — nothing that has to be
   installed at run time beyond those system libraries. No interpreter, no `.ini`,
   no extension loader.
3. **Linux first** — macOS is a development host, Linux is the target that must
   be green.
4. **Self-hosting is the floor, real-world applications are the goal** — every
   feature is judged by whether third-party PHP (`examples/symfony-console`, the
   T5 symfony corpus) compiles and runs through it.
5. A superset feature (async, FFI, modules, attributes) has no Zend oracle, so
   it needs its own tests and its own doc under `docs/`.

## Docs

- `README.md` — what it is, what it needs, how to use it.
- `docs/ROADMAP.md` — status + the gap matrix with repros. Update it when a gap
  closes.
- `docs/*.md` — user-facing guides (async, ffi, modules, http, memory, …).
- `docs/builtins.md` — GENERATED coverage of PHP's internal functions/classes per
  extension and tier (`b`/`l`/`s`/`p`/`i`). Regenerate with `php tools/builtins_audit.php`
  after adding a stdlib function, a builtin or a prelude class; never edit by hand.
- `docs/design/*.md` — design notes; `design/memory-abi.md` is the stone tablet.
- `docs/status/`, `docs/audit/`, `docs/superpowers/` — working notes, untracked.
