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
bin/build --seed               # cold bootstrap (Zend runs the compiler once to seed it)
bin/build --verify             # rebuild, then the fixpoint + suite gate
bin/build --auto-seed          # on a bootstrap gap, escalate to the seed (CI)
bin/build --fast               # -O1, application only, to bin/manticore.fast — NOT gate evidence
bin/compile                    # cold seed + stdlib build; fallback only, not the loop
```

Iterate with `bin/build`, not `bin/compile`. `bin/compile` runs the whole compiler
under Zend (~8 min); `bin/build` uses the installed native compiler (~1–2 min).

**A failed `bin/build` poisons `bin/manticore` and `lib/*.o`.** The build refuses
to write a library `.o` that contains an undefined-function trap, but a build that
died half-way leaves the previous artifacts in an unknown state. Re-run it to a
clean finish before trusting anything.

## Test

```bash
bash tests/aot/run.sh                # AOT suite: cases/*.php + expected/*.out, auto-discovered
bash tests/aot/run.sh -k <substr>    # filter cases (do this first — the full suite is minutes)
bash tests/aot/run.sh -j 0           # all cores (note: `-j 0` is two args)
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

## Pipeline

```
PHP source → Lexer → Parser → AST
  → LowerFromAst → ConstFold → DeadStore → InferTypes → VivifyRefArgs
  → NarrowReturns → InferTypes → InlineClosures → InferTypes → Monomorphize
  → FuseSplitJoin → TypeCheck (full; MANTICORE_TYPECHECK=0 → reprOnly) → NarrowReturns → CheckTypeDefs
  → ReflectAnalysis → DemoteCharLocals → InferEffects → InferAllocKind
  → ApplyMemoryMode → InsertMemoryOps → Verify
  → EmitLlvm (+ HoistAllocas, PruneIr) → LLVM IR → clang -c → cc → static binary
```

The driver that sequences this is `cmd_compile` in `src/Manticore/Main.php`.
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
| Memory ABI: offsets, tags, rc encoding | `src/Compile/MemoryAbi.php` (bumping `VERSION` ⇒ `bin/build --seed`) |
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
- **New syntax has no escape.** It is unusable inside `src/` until the generation
  that parses it is installed. Never ship a parser change together with tree code
  that needs it — the previous generation cannot build the tree, and only a cold
  seed recovers.
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
