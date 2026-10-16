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
| `--allow-undefined-traps` | | Do not refuse a library that contains undefined-function traps |
| `--keep-ir` | | Keep the intermediate `.ll` files |
| `-j` | `<n>` | Parallel clang jobs; `0` = one per core |
| `--analyze` | | Run the analyzer (default) |
| `--no-analyze` | | Skip the analyzer |
| `--analyze-strict` | | Fail with rc 65 on analyzer errors |
| `--progress` | `<mode>` | Progress output: `auto`\|`live`\|`plain`\|`json`\|`off` (default `auto`) |
| `--quiet` | | Silence progress and timings (diagnostics stay) |
| `--timings` | | Print a per-stage time table at the end |
| `--color` | `<when>` | Colour: `auto`\|`always`\|`never` (default `auto`) |

Exit codes:

| Code | Meaning |
|---|---|
| `0` | binary written |
| `64` | usage error: unknown flag, missing value, bad `--progress` / `--color` value |
| `65` | front end failed (parse / type check / empty IR), or `--analyze-strict` found errors |
| `66` | no input read: source missing or the source list is empty |
| `73` | `--emit-library`: cannot write the `.ll` file |
| `75` | `clang` failed (object, or library with `--emit-library`) |
| `76` | `cc` link failed |

```bash
manticore compile hello.php -o hello && ./hello
manticore compile src/ -o app -O2 -j 0
```

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
