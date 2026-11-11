# Command reference

Every `manticore` command, its options and exit codes. The text under each command is the
declared help (`manticore <command> --help`); `tests/cli/help.txt` pins it.

## Invocation

```
manticore <command> [options...]
```

- `manticore`, `manticore help`, `manticore --help`, `manticore -h` print the command list and
  exit `0`. An unknown command prints `unknown command: <name>` to stderr, then the same list,
  and exits `0`.
- `manticore <command> --help` (or `-h`) prints that command's summary, usage and options and
  exits `0`. Help is detected **after** option parsing, so `compile -o --help x` treats
  `--help` as the value of `-o`.
- `manticore --version` is `manticore version`.
- Options and positionals may be interleaved (`compile prog.php -o prog`). Accepted forms:
  `-o out`, `-O2`, `--memory=rc`, `--memory rc`, `--deep`. An unknown option or a missing value
  prints `<command>: unknown flag: …` / `missing value for …` and exits `64`.

## Commands

| Command | Summary |
|---|---|
| `compile` | Compile to a native binary (`-o <out>`); analyzes by default (`--no-analyze` skips, `--analyze-strict` gates on errors) |
| `build` | Build all targets from a `manticore.json` manifest (libraries + applications) |
| `analyze` | Static type analysis; report diagnostics, no codegen |
| `dump-llvm` | Read PHP source from stdin, emit LLVM IR on stdout |
| `dump-ast` | Parse PHP source and print the resulting AST |
| `dump-mir` | Parse PHP, lower to MIR, print the typed IR |
| `dump-llvm-mir` | Parse PHP, run MIR pipeline + EmitLlvm, print LLVM IR |
| `dump-sig` | Parse PHP, print the module-interface `.sig` (exported symbol table) |
| `split-ir` | Split a staged `.ll` module into N part files (dev tool) |
| `version` | Print compiler version |
| `upgrade` | Update the installed compiler to the latest release (`--check` only reports) |
| `doctor` | Check that this compiler and its toolchain work; exit 1 on a failure |
| `completions` | Print a shell completion script (bash, zsh or fish) |

## compile

```
manticore compile [options] <files…|manifest.json> -o <out>
```

| Option | Argument | Meaning |
|---|---|---|
| `-o` | `<path>` | Output path (default `a.out`) |
| `--memory` | `<mode>` | Memory mode: `rc`\|`arena`\|`hybrid` (default: `$MANTICORE_MEMORY`) |
| `--backend` | `<name>` | Codegen backend override |
| `-O` | `<level>` | Optimisation level: `0`\|`1`\|`2`\|`3`\|`s`\|`z` (default `2`) |
| `--prelude` | | `dump-*`: include the prelude in the dump |
| `--effects` | | `dump-mir`: show inferred effects |
| `--emit-library` | | Emit a library object instead of an executable |
| `--runtime` | | With `--emit-library`: this library is the bundled stdlib |
| `--allow-undefined-traps` | | Do not refuse a library that contains undefined-function traps; a reachable undeclared name is a warning, not an error |
| `--keep-ir` | | Keep the intermediate `.ll` files |
| `--strict` | `<list>` | Comma-separated strict checks: `shapes` (a PHPDoc `array{…}` shape is closed: an extra key is an error instead of a warning), `analyze` (= `--analyze-strict`) |
| `-j` | `<n>` | Parallel clang jobs; `0` = one per core |
| `--analyze` | | Run the analyzer (default) |
| `--no-analyze` | | Skip the analyzer |
| `--analyze-strict` | | Fail with rc 65 on analyzer errors |
| `--progress` | `<mode>` | Progress output: `auto`\|`live`\|`plain`\|`json`\|`off` (default `auto`) |
| `--quiet` | | Silence progress and timings (diagnostics stay) |
| `--timings` | | Print a per-stage time table at the end |
| `--color` | `<when>` | Colour: `auto`\|`always`\|`never` (default `auto`) |
| `--check` | | Run the front end only (parse, lowering, declaration, attribute and typedef checks), stop before type inference; exit `0` with no output file when clean |
| `--all` | | List unreachable undeclared names too (hidden notes, see below) |

Exit codes:

| Code | Meaning |
|---|---|
| `0` | binary written |
| `64` | usage error: unknown flag, missing value, bad `--progress` / `--color` value |
| `65` | front end failed (every parse / lowering / declaration / attribute / typedef error is reported, then exit), type check or empty IR failed, or `--analyze-strict` found errors |
| `66` | no input read: source missing or the source list is empty |
| `73` | `--emit-library`: cannot write the `.ll` file |
| `75` | `clang` failed (object, or library with `--emit-library`) |
| `76` | `cc` link failed |

```bash
manticore compile hello.php -o hello && ./hello
manticore compile src/ -o app -O2 -j 0
```

### Front-end diagnostics and `--check`

The front end collects every error instead of stopping at the first. Each prints as
`<file>:<line>: <severity>[<code>]: <message>`, followed by `front end: N error(s), M warning(s)`;
any error means exit `65`. Codes:

| Code | Meaning |
|---|---|
| `parse.error` | syntax error (the file is skipped, the rest still lowers) |
| `lower.unsupported` | construct the compiler does not support |
| `decl.error` | invalid class / function / constant declaration |
| `attr.error` | invalid attribute |
| `typedef.error` | invalid type definition |
| `lower.internal` | internal lowering failure, recorded at a function / method / top-level boundary |

Four more codes flag a name in the program's own sources (app and vendor) that no compiled source,
prelude, stdlib `.sig` extern or compiler builtin declares:

| Code | Meaning |
|---|---|
| `undefined.class` | class / interface / trait / enum used in `new`, static access, `instanceof`, `catch`, a type hint, `extends`, `implements` or `use` and never declared (a missing package, or an extension class) |
| `undefined.function` | direct call of a function that is not declared, not a stdlib / FFI function and not a builtin |
| `undefined.constant` | bare constant that is not declared |
| `unsupported.builtin` | the name is not declared, but PHP itself defines it (`request_parse_body`, `finfo_open`, `GMP_ROUND_ZERO`, …): a gap in Manticore, not a missing package. Always a **warning**, never an error, reachable or not; the message reads `function f is part of PHP but not implemented by Manticore`. The binary builds and the use throws `Error` if it runs |

They are grouped: one line per (code, name), at the most severe site, with ` (N uses)` when the name
is used more than once. A name declared only conditionally behind a guard that is false in this build
(for example `fake()` behind `class_exists(Faker\Factory::class)`) is reported as undeclared.

Their severity comes from **reachability**: which functions the program can run, computed from the
roots (`__main`, include units, the reflection tables of a class in use, global initialisers) over the
lowered program. It has two levels. *Statically reached* follows explicit edges only: named calls,
`new C`, `C::m()`, `$o->m()` over the classes the program instantiates, closure literals, callable
literals (`'f'`, `'C::m'`, `[C::class, 'm']`, `[$o, 'm']`) and dynamic targets whose value is a set of
literals. *Maybe reached* also assumes that a call by a runtime name (`$f()`, `call_user_func($name)`),
`new $class`, `$o->$method()` or `[$class, $method]` reaches everything it could. A class's own code
(`extends`, `implements`, `use`, constants, property defaults) counts once the class is instantiated,
statically referenced or an ancestor of such a class.

| Site | Tier | Effect |
|---|---|---|
| statically reached, *hard* use (`new`, static access, call, constant read, `extends` / `implements` / `use`), not guarded | **error** | exit `65`; the message ends with ` (reachable: f <- g <- root)`, the call path that reaches it |
| maybe reached, a *soft* use (type hint, `instanceof`, `catch`: these never throw for a missing class), or guarded | warning | exit code unchanged |
| not reached at either level | note | hidden; the summary line counts them, `--all` lists them |

A site is *guarded* when it sits inside the branch of an `if`, `?:`, `&&` / `||` operand, or after an
early exit (`if (!class_exists(X::class)) { throw …; }` in the same statement list, switch arm or
`try` body), whose condition calls `class_exists`, `interface_exists`, `trait_exists`, `enum_exists`,
`function_exists`, `method_exists`, `is_callable`, `extension_loaded` or `defined`. A guard is also followed through
a helper: a function or method whose body is a single `return <guard expression>;` (`self::isSupported()`,
`$this->supports()`, `has_apcu()`), including a `&&` of guards, and a local assigned from a guard
and not reassigned (`$ok = class_exists($c); if ($ok) { … }`). A helper that does more than return
the guard, or one that calls another helper, is not followed.

An undeclared name never escalates to an error when it matches a manifest
[`allowUndefined`](#allowundefined) pattern, with `--allow-undefined-traps` (the binary is built and
the use throws `Error` at run time if it executes), when the front end already reported another error
(a parse or lowering error can hide the declaration), or, for `undefined.constant`, when the program
defines a constant under a runtime name (`define($name, …)`). `MANTICORE_REACH_DUMP=1` prints the
reached functions (`reach:` static, `reach?:` maybe) and used classes (`class:` / `class?:`).

### allowUndefined

A reachable use of a missing optional package is a true error: the path throws if it runs. When the
application accepts that (the feature is never used, or the extension is absent on purpose), list the
names in the per-application `allowUndefined` field of `manticore.json`:

```json
{"applications": [{"name": "app", "src": "src", "output": "bin/app",
  "allowUndefined": ["apcu_*", "Memcached", "Symfony\\Component\\Serializer\\*"]}]}
```

Each entry is a glob matched against the whole finding name, case-insensitively: `*` matches any run
of characters, everything else is literal; namespaces use `\` (written `\\` in JSON). A matching
`undefined.*` finding is capped at a warning; names that do not match keep their tier, so a new
missing dependency still fails the build. Keep patterns narrow: an exact name or one package
namespace. The list applies to that application only. The examples use it for the optional
packages and extensions they do not install.

`--check` (on `compile` and `build`) stops after these checks, before `InferTypes`: exit `0` and no output
file when clean, `65` with the diagnostics otherwise. In `analyze --deep` the same errors feed the
analyzer and lowering continues.

## build

```
manticore build [options] <manticore.json>
```

Takes the `compile` options above (except `--analyze`, `--analyze-strict`) plus:

| Option | Argument | Meaning |
|---|---|---|
| `--libs-only` | | Build library targets and stop (cold seed) |
| `--apps-only` | | Build application targets only |
| `--no-analyze` | | Accepted for compile parity; build does not run the analyzer |

`--libs-only` and `--apps-only` are mutually exclusive (`64`). Exit codes: `0` ok; `64` usage;
`65` manifest conflict between targets (e.g. a class defined in two libraries); `66` manifest, entry point or target sources cannot be read, or an unknown extension is wanted; `75` clang
failed; `76` link failed. Manifest format: [`modules.md`](modules.md).

```bash
manticore build manticore.json --timings
```

## analyze

```
manticore analyze [options] [paths…]
```

Takes the shared options `-o`, `--memory`, `--backend`, `-O`, `--prelude`, `--effects`,
`--emit-library`, `--runtime`, `--allow-undefined-traps`, `--keep-ir`, `-j` (declared for
parity, as in the help) plus:

| Option | Argument | Meaning |
|---|---|---|
| `--deep` | | Also run the compiler's MIR type passes (heavier) |
| `--json` | | Machine-readable output |
| `--baseline` | `<path>` | Suppress findings listed in this baseline file |
| `--generate-baseline` | `<path>` | Write a baseline file from current findings |
| `--only` | `<prefixes>` | Keep only diagnostic codes starting with these prefixes (comma list); gates on any survivor |

Exit codes: `0` no error-severity findings (with `--only`: no surviving finding); `1` an error
finding (with `--only`: any surviving finding); `64` usage; `73` cannot write the
`--generate-baseline` file.

```bash
manticore analyze src --only undefined.,parse.error
```

## dump-* and split-ir

`dump-llvm`, `dump-ast`, `dump-mir`, `dump-llvm-mir` and `dump-sig` take
`[options] [files…]` and the same shared options as `compile` up to `-j`
(`--prelude` and `--effects` are the ones that matter here). `dump-llvm` reads PHP from stdin.
They print to stdout.

Exit codes: `0` ok; `64` usage; `66` input cannot be read; `65` the stage failed.

The `dump-*` commands do **not** link the stdlib: a call into it resolves as `unknown`, and a bug
in that path can vanish from the dump. Read the final binary when that matters.

```bash
manticore dump-mir --effects prog.php | less
```

`split-ir` is a dev tool:

```
manticore split-ir <module.ll> <parts> <outbase>
```

Exit codes: `0` ok; `64` usage; `66` module unreadable; `65` split failed.

## version, upgrade

```
manticore version [--check]
manticore upgrade [--check] [--version X]
```

`version` prints `manticore <X.Y.Z>`.

| Option | Argument | Meaning |
|---|---|---|
| `--check` | | Report installed and latest version; change nothing. Exit 1 if an update exists, 2 on error (`version --check` is the same as `upgrade --check`) |
| `--version` | `<X>` | (`upgrade`) Install this release instead of the latest |

`--check` prints `installed A / latest B` followed by `update available`, `up to date` or
`installed is newer`. Exit codes of `--check`: `0` current (or newer), `1` update available,
`2` cannot check.

`upgrade` without `--check` runs the installed `install.sh` (`$MANTICORE_HOME/share/install.sh`)
with `MANTICORE_STRICT=1`:

- already at the target version: says so, exit `0`;
- installed is newer than the latest release and no `--version` given: nothing to do, exit `0`;
- `--version` older than installed: prints `downgrading A -> B` and proceeds;
- otherwise the exit code is `install.sh`'s.

**Foreign installs.** `upgrade` and `version --check` refuse to touch a compiler that
`install.sh` did not install (no `share/install.sh` beside `bin/`). They print which installer
owns it (Homebrew, a system package manager, the container image, or "an unknown installer"),
say nothing was changed, and exit `2`.

`install.sh` in strict mode (`MANTICORE_STRICT=1`, which `upgrade` sets): a miss is fatal and
never falls back to a source build. Exit codes:

| Code | Meaning |
|---|---|
| `2` | network / release lookup failed |
| `3` | no published build for this platform |
| `4` | checksum mismatch |
| `5` | failure after the swap (the previous install is rolled back if the swap was this run's) |

`install.sh --check` prints `latest=<ver>` and `platform=<os>-<arch>` and installs nothing.
Install methods: [`install.md`](install.md).

## doctor

```
manticore doctor [--offline] [--no-smoke]
```

| Option | Meaning |
|---|---|
| `--offline` | Skip the update check |
| `--no-smoke` | Skip compiling and running a hello-world |

Prints one line per check, `[ OK ]`, `[WARN]` or `[FAIL]`, then `N ok, N warnings, N failures`.
A hint line follows every non-OK result that has one. Exit `1` if any check failed, else `0`;
warnings never fail.

| Check | OK | WARN | FAIL |
|---|---|---|---|
| compiler | always: `manticore <version>` and its directory | | |
| install | installed by `install.sh` | not installed by it (names the owner) | |
| clang | found, version >= 15 | | missing, version unparsable, or < 15 |
| cc | found on `PATH` | | missing |
| stdlib object | `lib/manticore_stdlib.o` found | | not found |
| stdlib signature | `lib/manticore_stdlib.o.sig` found | | not found |
| prelude | prelude sources found | | not found |
| pcre2 | `pcre2-config --version` answers | not found | |
| pkg-config | present | missing (openssl / zlib are then not probed) | |
| openssl, zlib | `pkg-config --modversion` answers | not found | |
| smoke test | compiled and ran a hello-world | | compile or run failed (last 3 log lines shown) |
| update | up to date, or skipped (`--offline`, or not managed by `install.sh`) | update available, or latest unknown | |

## completions

```
manticore completions <bash|zsh|fish>
```

Prints the script to stdout. Any other argument prints `completions: expected one of bash, zsh,
fish` and exits `64`.

```bash
manticore completions bash > ~/.local/share/bash-completion/completions/manticore
manticore completions zsh  > ~/.zfunc/_manticore     # ~/.zfunc must be in fpath before compinit
manticore completions fish > ~/.config/fish/completions/manticore.fish
```

The scripts are generated from the command and option declarations, so they match the installed
compiler; regenerate them after an upgrade.

## Build progress

`compile` and `build` report progress on **stderr only**; stdout is never touched.

| Mode (`--progress`) | Output |
|---|---|
| `live` | one line redrawn in place (`● EmitLlvm  1.2s  (total 4.0s) · functions 512/1881`), ends with `✓ done in 7.1s` (`✗ failed after …`) |
| `plain` | one line per finished stage, a `label n/n` line when a counter completes, a final `done in …` / `failed after …` |
| `json` | one JSON object per line |
| `off` | nothing |
| `auto` | the default, see below |

`auto`: with `--quiet`, off. Otherwise `live` when stderr is a terminal; `plain` when it is not
but `CI` is set to a non-empty value; `off` otherwise, so piped builds stay silent.
`--quiet` silences progress and timings; diagnostics are still printed. `--timings` appends the
15 slowest stages, longest first.

Counters: `files`, `functions` and, for a split build (`MANTICORE_SPLIT_JOBS` with `-j 0`),
`clang` (parts compiled so far / total, cache hits included).

**Colour** is on only if every rule passes, first match wins: `--color=never` off; `NO_COLOR`
set and non-empty off; `TERM=dumb` off; `--color=always` on; otherwise on exactly when stderr is
a terminal. `json` is never coloured; `plain` is coloured only with `--color=always`.

`plain` (`manticore compile h.php -o h --progress=plain`, middle of the list elided):

```
      files 1/1
[ 1] stdlib .sig -> extern decls  0.0s
[ 2] prelude-demand (re-lex)  0.0s
[ 3] parse  0.0s
[ 4] prelude read (all files)  0.0s
[ 5] LowerFromAst  0.0s
…
[33] property scan  0.0s
      functions 1887/1887
[34] emit preamble  0.0s
[35] hoist allocas  0.0s
[36] prune IR  0.0s
[37] EmitLlvm  0.0s
[38] clang -O2 -c whole module  0.2s
[39] link  0.0s
done in 0.7s
```

`json` events (`--progress=json --timings`):

| `event` | Fields |
|---|---|
| `stage_start` | `name`, `t_ms` (since start) |
| `stage_end` | `name`, `ms`; `fns`, `classes` only when known |
| `counter` | `label`, `done`, `total` |
| `done` | `ms`, `rc` |
| `timing` | `name`, `ms` (only with `--timings`, after `done`) |

```
{"event":"counter","label":"files","done":1,"total":1}
{"event":"stage_start","name":"stdlib .sig -> extern decls","t_ms":119}
{"event":"stage_end","name":"stdlib .sig -> extern decls","ms":6,"fns":1820}
{"event":"stage_start","name":"prelude-demand (re-lex)","t_ms":126}
{"event":"stage_end","name":"prelude-demand (re-lex)","ms":0}
…
{"event":"timing","name":"InferTypes #3","ms":4}
```

`--timings` in `plain`:

```
done in 0.7s
timings (longest first):
     298ms  clang -O2 -c whole module
      49ms  link
      28ms  EmitLlvm
…
```

## Environment

| Variable | Effect |
|---|---|
| `MANTICORE_HOME` | Install prefix used by `install.sh` (default `~/.manticore`); `upgrade` passes the running compiler's prefix. Also the base of the default object-cache directory (`$MANTICORE_HOME/cache/obj`) |
| `MANTICORE_VERSION` | `install.sh`: install this release instead of the latest (`upgrade --version` sets it) |
| `MANTICORE_STRICT` | `install.sh`: `1` makes a miss fatal with exit 2/3/4/5; `upgrade` sets it |
| `MANTICORE_MEMORY` | Default for `--memory` (`rc`\|`arena`\|`hybrid`) |
| `MANTICORE_SPLIT_JOBS` | Force a split build with this many clang jobs (capped at 64); the README pairs it with `-j 0` |
| `MANTICORE_OBJ_CACHE` | Content-addressed object cache: on by default for split builds, off for a single unit unless `1`; `0` disables it everywhere |
| `MANTICORE_OBJ_CACHE_DIR`, `MANTICORE_OBJ_CACHE_MAX_MB` | Cache directory and size bound (default 2048 MB) |
| `MANTICORE_STATS` | Non-empty and not `0`: full compile-time profile on stderr; progress `auto` then picks `plain` on a TTY or under `CI`, else `off` |
| `MANTICORE_PHASE_TRACE` | Non-empty and not `0`: per-phase timeline only |
| `CI` | Non-empty: `--progress=auto` picks `plain` when stderr is not a terminal |
| `NO_COLOR` | Non-empty: progress colour off, even with `--color=always` 
| `TERM` | `dumb`: progress colour off |

## See also

- [`install.md`](install.md): install methods, `upgrade`, completions
- [`../README.md`](../README.md): overview and usage
- [`modules.md`](modules.md): `manticore.json` and `manticore build`
