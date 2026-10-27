# Semantic core (P2a)

Library layer under the future LSP: text in, diagnostics and name queries out, one file edit
at a time. Spec: `docs/superpowers/specs/2026-10-26-semantic-core-design.md` (untracked).
Goals measured here: **G1** single-file edit to refreshed diagnostics in single-digit ms on a
mid-size project; **G4** `analyze --json` unchanged. Anti-goal: the LSP process must not keep
every AST of every file alive.

## Architecture as built

| Component | Responsibility |
|---|---|
| `Analyze\Index` | Project symbol table (user classes, interfaces, traits, enums, functions) keyed by lowercased FQN. Incremental: `replaceFile` drops the file's previous contribution and adds the new one, `removeFile` forgets it. A file keeps its place in each FQN's *declarer order*, so when two files declare the same name the winner does not flip because one of them was edited. Also answers `declaringFile`, `declSpan`, `dependentsOf`, `implementersOf`. Stdlib function names enter through `addExternFunctions`. |
| `Semantic\NameIndex` | Per-file name occurrences (`NameOccurrence`: kind, resolved lowercased name, range) plus the sites a name-based rename cannot be sure of (`UncertainSite`: dynamic members, string literals equal to a name). `occurrenceAt(file, line, col)` finds the occurrence under a cursor; `occurrences(kind, name)` is the reference list. |
| `Semantic\SourceType` / `SourceTypeParser` | Source-faithful type (what the author wrote, not the lowered `Compile\Mir\Type`), immutable; the parser reads hints and docblock types, depth-limited, rejects null-only unions. |
| `Semantic\LibrarySymbols` | The library universe the analyzer is closed over: the prelude (54 parsed files) and the stdlib function names from `lib/manticore_stdlib.o.sig`, normalised the way `LowerFromAst` aliases namespaced externs (`normalizeNames`). `load()` caches per process; `fromParts()` is for tests and hosts that supply their own. |
| `Analyze\ParseDiagnostics` | The one mapping from tolerant-parse errors to `parse.error` diagnostics, shared by the CLI and the Workspace. |
| `Semantic\Workspace` | Facade: `open/update/close/remove`, `diagnostics`, `definitionOf`, `referencesTo`, `uncertainSitesFor`. Holds Index + NameIndex + the ASTs of open files. Diagnostics are computed per call from the current Index, so an edit in file A is seen by every dependent B on B's next `diagnostics` call with no invalidation bookkeeping. `close` drops the AST/text but keeps the file's Index and NameIndex entries (project membership survives); `remove` forgets the file. |

`LibrarySymbols` caveats:

- **Run-time prelude lookup.** `load()` resolves the prelude and the sig at run time relative
  to the executable (`MANTICORE_PRELUDE`, `../prelude`, `../lib/prelude`, ...; sig:
  `MANTICORE_STDLIB_SIG`, `../lib/manticore_stdlib.o.sig`). A host binary must ship `lib/`
  next to it. There is no embedded copy.
- **Fail loud.** An empty prelude makes `new Workspace(...)` throw `RuntimeException`: with no
  prelude every `Exception`, `Countable`, ... would be reported as an unknown class, so
  silence would be wrong output, not a degraded mode. A missing sig is the opposite
  (stdlib names empty): it is not detected here, so a host should check
  `stdlibFunctionNames()`.
- Under Zend the `Manticore\file_exists` / `read_file` helpers are throwing stubs, so
  `load()` returns the prelude but zero stdlib names. `tools/bench_semantic.php` works around
  it by reading the sig through plain Zend functions (see Tooling).

A file with syntax errors reports only its `parse.error` diagnostics and contributes nothing
to the Index and NameIndex while it has them (the walkers are not Error-node aware yet).

## Parity (G4)

`php tools/bench_semantic.php <dir> --parity` compares, per error-free file,
`Workspace::diagnostics` (`checkUndefined = true`, what the CLI uses for a directory) with that
file's entries in `bin/manticore analyze --json <dir>` (file, line, col, severity, code,
message; order-insensitive).

| Corpus | Host | Files checked | Diagnostics | Differences |
|---|---|---|---|---|
| `src/` (330 files) | native bench | 330 | 280 | 0 |
| `src/` | Zend (php 8.5.12) | 330 | 280 | 0 |
| `tests/analyze/cases` (27 files, 2 with parse errors skipped) | native | 25 | 44 | 0 |
| `tests/analyze/cases` | Zend | 25 | 44 | 0 |
| `examples/symfony-console/src` (1 file; the example's `vendor/` is not in the tree) | both | 1 | 2 | 0 |

Legitimate CLI-vs-library differences that exist but did not show up as output differences:
the CLI parses with `parseTolerant($src, '', false, false)` (no ranges, no name table), the
Workspace parses with both; the analyzer reads neither. Parse-error files are excluded from
the comparison (the Workspace reports the same `parse.error` set, but the CLI also analyzes
the rest of the project without them, and so does the Workspace by construction).

## Latency and memory (G1)

Method: `tools/bench_semantic.php src --edits 200`. All `*.php` under the directory are
opened into one `Workspace`; then 200 times a pseudo-random file (fixed LCG seed, uniform
over files) gets a comment line appended, `update()` and `diagnostics()` of that file are
timed with `hrtime`. macOS arm64 dev host, one run each, numbers rounded.
**Native** = the bench compiled by `bin/manticore build` (all of `src/` plus the bench as
entry, `stdlib:false`, as the compiler itself is built) - this is the number that matters
for an LSP that ships as a native binary. **Zend** = php 8.5.12, no opcache tuning,
sanity reference only.

`src/` is 330 files; the median file is 71 lines but the tail is long (7k-11k lines: the
`EmitLlvm*`, `LowerFromAst`, `RuntimeLibrary` files), which dominates p95.

| Per edit, ms | native p50 | native p95 | native max | Zend p50 | Zend p95 | Zend max |
|---|---|---|---|---|---|---|
| update + diagnostics | 1.10 | 22.5 | 130 | 4.57 | 122 | 514 |
| update (parse + Index + NameIndex) | 0.65 | 12.8 | 64 | 2.66 | 67.7 | 275 |
| diagnostics (rules) | 0.48 | 10.1 | 77 | 2.09 | 51.6 | 239 |
| parse alone (reference) | 0.64 | 12.2 | 57 | 2.63 | 67.0 | 347 |

One-off costs:

| | native | Zend |
|---|---|---|
| library load (54 prelude files + sig) | 191 ms | 825 ms |
| cold `open` of 330 files | 865 ms | 4831 ms |
| `diagnostics` of all 330 files | 899 ms | 3736 ms |
| peak RSS after cold load (`ru_maxrss`) | 221 MB | 370 MB |
| peak RSS after 200 edits | 299 MB | 416 MB |

Verdict against G1: **p50 met natively (1.1 ms, single-digit)**, as is the typical small
file. The tail is not: p95 22 ms and max 130 ms on the multi-thousand-line files. Where the
time goes: it tracks file size, and `update` is almost entirely the parse (0.65 ms vs
0.64 ms for parsing alone: Index replace + NameIndex add are in the noise); the rest is the
rules over the one file's AST (`diagnostics`). So the budget for the large-file tail is the
tolerant parser and the rule walkers, not the Index. A faster tail needs an incremental
parse (re-parse the edited top-level declaration) or a cheaper walker, neither of which is
in P2a.

Memory: `Workspace::open` keeps the AST of every open file (the benchmark opens all 330, so
the 221 MB native RSS is the worst case of "every file open"). `close` drops the AST and
text and keeps only the Index/NameIndex entries, which is the intended LSP shape (open
editors only); the bench does not close files, so the closed-project footprint is not
measured here. RSS growth over 200 edits is +78 MB native (`update` replaces an AST and
the allocator does not return the old one to the OS in a short run); a longer soak was not
run.

## Tooling

`tools/bench_semantic.php <dir> [--edits N] [--parity] [--cli-json FILE]`. Runs under Zend
directly (`php -d xdebug.mode=off tools/bench_semantic.php src --edits 200 --parity`). To
compile it natively, build a manifest whose `src` is a copy of `src/` without `main.php` and
whose `entry` is a copy of the bench (with `dirname(__DIR__)` replaced by the repo path), place
the binary so `../lib` is the repo's `lib/`, and `bin/manticore build -j 0 manifest.json`
(~2 min). `--cli-json` reads a saved `analyze --json` output instead of shelling out.
Native finding while writing it: the combination `sort($a); sort($b); $a !== $b` over two
`string[]` built in a loop and `array_diff` on them SIGSEGVs in the compiled binary (the
bench compares with `in_array` instead). Not minimised or filed here.

## What remains

**P2b - tolerant analyzer**
- Error-aware walkers and an `ErrorMember` node, so a file with syntax errors still gets
  diagnostics and contributes declarations (today: parse errors only, nothing indexed).
- `Diagnostic` end ranges (only a start line/column exist).
- Declaration-name ranges, so references/rename include the declaration itself
  (`referencesTo` lists use sites only).
- Per-part interpolation spans (names inside interpolated strings).
- Anonymous-class marker.
- Global constants in the Index (`definitionOf` on a constant returns null).

**P2c** - `typeAt` and narrowing.

**Known gaps from the task reviews**
- `SourceType::toString` loses the parentheses of a callable with a union return;
  `callable(): ?A` is unsupported by `SourceTypeParser`.
- `withDoc` has no production caller.
- `NameIndex::occurrenceAt` relies on NameRefs never overlapping.
- `UncertainSite` has no end position.
- Docblock and `Invoke` uncertain sites are not implemented.
- `Workspace::open` does not catch parser exceptions, and a file with errors keeps an AST
  that nothing uses.
- `perform_analysis` (the CLI) still uses `Analyzer::run`, not the Workspace path; the two
  agree on every corpus above, but nothing enforces it yet beyond this bench.
- Latency tail on multi-thousand-line files (see G1 verdict).
- Four `tests/analyze` cases fail before this work and still do: `flow_depth`, `json_smoke`,
  `type_mismatch`, `undefined_member`.
