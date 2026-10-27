# Semantic core (P2a + P2b)

Library layer under the future LSP: text in, diagnostics and name queries out, one file edit
at a time. Goals measured here: **G1** single-file edit to refreshed diagnostics in single-digit ms on a
mid-size project; **G4** `analyze --json` unchanged. Anti-goal: the LSP process must not keep
every AST of every file alive.

## Architecture as built

| Component | Responsibility |
|---|---|
| `Analyze\Index` | Project symbol table (user classes, interfaces, traits, enums, functions) keyed by lowercased FQN. Incremental: `replaceFile` drops the file's previous contribution and adds the new one, `removeFile` forgets it. A file keeps its place in each FQN's *declarer order*, so when two files declare the same name the winner does not flip because one of them was edited. Also answers `declaringFile`, `declSpan`, `dependentsOf`, `implementersOf`. Stdlib function names enter through `addExternFunctions`. |
| `Semantic\NameIndex` | Per-file name occurrences (`NameOccurrence`: kind, resolved lowercased name, range) plus the sites a name-based rename cannot be sure of (`UncertainSite`: dynamic members, string literals equal to a name). `occurrenceAt(file, line, col)` finds the occurrence under a cursor; `occurrences(kind, name)` is the reference list. |
| `Semantic\SourceType` / `SourceTypeParser` | Source-faithful type (what the author wrote, not the lowered `Compile\Mir\Type`), immutable; the parser reads hints and docblock types, depth-limited, rejects null-only unions. A callable return may be a union (printed parenthesized), an intersection or `?A`. |
| `Semantic\LibrarySymbols` | The library universe the analyzer is closed over: the prelude (54 parsed files) and the stdlib function names from `lib/manticore_stdlib.o.sig`, normalised the way `LowerFromAst` aliases namespaced externs (`normalizeNames`). `load()` caches per process; `fromParts()` is for tests and hosts that supply their own. |
| `Analyze\ParseDiagnostics` | The one mapping from tolerant-parse errors to `parse.error` diagnostics, shared by the CLI and the Workspace. |
| `Semantic\Workspace` | Facade: `open/update/close/remove`, `diagnostics`, `definitionOf`, `referencesTo`, `uncertainSitesFor`. Holds Index + NameIndex + the ASTs of open files (a file with syntax errors keeps its tolerant AST; `openFileCount` counts live ASTs). Diagnostics are computed per call from the current Index, so an edit in file A is seen by every dependent B on B's next `diagnostics` call with no invalidation bookkeeping. `definitionOf` falls back from an undeclared namespaced function name to the global one and takes the symbol kind into account; `referencesTo` includes `use` imports and links global and unqualified namespaced uses of one function or constant. `close` drops the AST/text but keeps the file's Index and NameIndex entries (project membership survives); `remove` forgets the file. |

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

- **Reserved `lib:` labels.** Library files are labelled `lib:prelude/<name>.php`, never a
  bare relative path, so a user file named `prelude/exceptions.php` cannot displace one.
  `Workspace::open/update/close/remove` throw `InvalidArgumentException` for a `lib:` URI. The
  label is not a disk path (the prelude is found through several candidate roots), so an LSP
  layer that wants to open a library file maps `lib:prelude/` to its prelude directory.

**Duplicate declarations.** `Index` keeps the first declarer of a name (declarer order, stable
under edits). The Workspace indexes the library first, so a library declaration beats a user
one, and among user files the first opened wins. `Analyzer::run` (the CLI) deliberately keeps
the legacy rule for byte-compatible output: it feeds the library first and the user files in
reverse, so the library still wins and the LAST user file wins. The two differ only for a name
declared in two user files.

## Tolerant analysis (P2b)

**Errored files keep their AST.** `Workspace::open` stores the tolerant AST of a file with
syntax errors (`ParsedFile::$hasErrors`), indexes what parsed, and analyzes it; the walkers
skip `Error`/`ErrorMember` nodes. `diagnostics` appends the `parse.error` set.

**Suppression post-filter.** Findings that depend on the broken region are dropped, not
reported as noise: `undefined.class` / `undefined.function` when a `use`, namespace or
declaration statement failed (`brokenPreamble`), and `arg.count` / `arg.type` / `return.*`
whose range holds a parse error or error expression (`ParsedFile::hitsRange`).

**Sticky declarations.** When a file is re-indexed while it has errors, declarations (classes,
functions, constants) it declared in its previous parse and cannot see now are kept
(`Index::replaceFile` merges the old contribution under the new one). A mid-edit syntax error
therefore does not make every dependent report `undefined.*`; a clean parse replaces the file's
contribution exactly, so a declaration really deleted disappears then.

**Partial classes.** A class whose body had errors is indexed with `ClassInfo::$partial`; the
member-existence rules (`undefined.method`, property and constant lookups) treat it as open and
stay silent on members the lost region might have declared.

**Name table.** Besides use sites, `NameIndex` holds `decl` entries (`NameOccurrence` kind of
the declaration, range of the name token) and `role` entries for `use` imports. The parser's
`declNames` level selects what is recorded: 0 = none (strict-compatible), 1 = class-likes,
functions and global constants, 2 = also members (methods, properties, class constants, enum
cases). Members, properties and constants are level 2 only. Enum cases and class constants
carry kind `member`, so `definitionOf`/`referencesTo` resolve between a declaration and its
others. With `declNames` = 0 a class attribute shifts the recorded header position (known,
level 0 is not used by the Workspace). Anonymous classes are named
`__anon_class_<hash>_<line>_<col>` (tolerant parse only), unique per site, so two anonymous
classes never shadow one another.

**Constants.** Global constants are indexed: `const X` (namespaced, name range = the bare
name) and `define('X')` (always global unless the string has a namespace; range = the string
without its quotes, so a rename replaces just the name; a literal with escapes keeps the whole
literal range). Constants are case-sensitive (only the namespace part is folded;
`NameOccurrence::$resolved` keeps the written case). Integer-like names (`define('123')`)
are not indexed: PHP coerces such array keys to int, which would make the map mixed-keyed.
`Index::constKey` is the one normalisation; the first declarer wins like classes and functions.

**Name-precise ranges.** Diagnostics carry end ranges, anchored on the declaration name for
declaration-level findings. `extends`, `implements`, trait `use` and `catch` types are narrowed
to the written name. Limits: method return-type findings keep the whole-method span (the parser
has no return-type token range); the header-name rule takes the first `count(extends)+count(implements)`
name entries and relies on the parser's emission order.

**Interpolation.** In a double-quoted string each interpolated part (`$a`, `{$a->b}`) has its own
range, so names inside it are found. Heredoc/nowdoc fall back to the whole-string range;
`"${x}"` stays a literal; a nested `"{$a["b"]}"` is broken by the lexer (pre-existing).

**CLI vs Workspace winner.** For a name declared in two user files the CLI keeps the LAST file,
the Workspace the FIRST (see Duplicate declarations); the one `dup_decl_user` case shows as a
difference when the parity bench runs over `tests/analyze/cases`.

## Parity (G4)

`php tools/bench_semantic.php <dir> --parity` compares, per error-free file,
`Workspace::diagnostics` (`checkUndefined = true`, what the CLI uses for a directory) with that
file's entries in `bin/manticore analyze --json <dir>` (file, line, col, severity, code,
message; order-insensitive).

| Corpus | Host | Files checked | Diagnostics | Differences |
|---|---|---|---|---|
| `src/` (333 files) | native bench | 333 | 281 | 0 |
| `src/` | Zend (php 8.5.12) | 333 | 281 | 0 |
| `tests/analyze/cases` as ONE directory (30 files, 2 with parse errors skipped) | native and Zend | 28 | 42 | 13 (see below) |
| `tests/analyze/cases`, subdirectories and `arg_count` / `string_bitwise` run alone | Zend | per case | - | 0, except `dup_decl_user` = 1 |
| `examples/symfony-console/src` (P2a run) | both | 1 | 2 | 0 |

The 13 differences on the merged cases directory are not Workspace bugs: that directory is a
union of independent cases, and cases that declare the same name (the `Box` of `arg_count`
against the subdirectory cases, `dup_decl_user`, `undefined_member`) hit the documented
duplicate-declaration rule (CLI: last user file wins; Workspace: first). Run alone, `arg_count`
and `string_bitwise` give 0 differences, as do all subdirectories but `dup_decl_user` (the rule
itself, 1 diagnostic). `bin/manticore analyze --json src` is byte-identical to main's binary.

Legitimate CLI-vs-library differences that exist but did not show up as output differences:
the CLI parses with `parseTolerant($src, '', false, false)` (no ranges, no name table), the
Workspace parses with both; no output difference attributable to that was observed. Parse-error files are excluded from
the comparison (the Workspace reports the same `parse.error` set, but the CLI also analyzes
the rest of the project without them, and so does the Workspace by construction).

## Latency and memory (G1)

Method: `tools/bench_semantic.php src --edits 200 --broken 50` (P2b record, 333 files). All `*.php`
under the directory are opened into one `Workspace`; then 200 times a pseudo-random file (fixed LCG
seed, uniform over files) gets a comment line appended, `update()` and `diagnostics()` of that
file are timed with `hrtime`. macOS arm64 dev host, one run each, numbers rounded.
**Native** = the bench compiled by `bin/manticore build` (all of `src/` plus the bench as
entry, `stdlib:false`) - the number that matters for an LSP that ships as a native binary.
**Zend** = php 8.5.12, sanity reference only.

`src/` median file is 71 lines but the tail is long (7k-11k lines: the `EmitLlvm*`,
`LowerFromAst`, `RuntimeLibrary` files), which dominates p95.

| Per edit, ms (200 appended-comment edits) | native p50 | native p95 | native max | Zend p50 | Zend p95 | Zend max |
|---|---|---|---|---|---|---|
| update + diagnostics | 1.07 | 18.3 | 124 | 5.05 | 78.5 | 516 |
| update (parse + Index + NameIndex) | 0.59 | 9.6 | 66.8 | 2.83 | 43.5 | 281 |
| diagnostics (rules) | 0.44 | 8.7 | 56.9 | 2.05 | 35.0 | 236 |
| parse alone (reference) | 0.57 | 9.0 | 59.3 | 2.85 | 44.9 | 275 |

Broken-file edits (`--broken 50`: 50 files x 3 mutations = 150 - truncate at a random byte,
inject a garbage line, delete a random line; update + diagnostics of the mutated file):

| ms | native p50 | native p95 | native max | Zend p50 | Zend p95 | Zend max |
|---|---|---|---|---|---|---|
| update + diagnostics, broken file | 0.93 | 27.7 | 116 | 3.30 | 130 | 524 |

Sticky-declaration check: after each mutation the diagnostics of up to 12 untouched dependent
files (files mentioning a name the mutated file declares) are compared to their baseline.
**New `undefined.*` for declarations the mutation did not delete: 0** (native and Zend). 5 new
ones appeared for declarations the mutation itself removed (truncation/line deletion), which
is correct. After restoring each file, the diagnostics of the file and its dependents return
exactly to the original set (0 differences).

One-off costs:

| | native | Zend |
|---|---|---|
| library load (54 prelude files + sig) | 209 ms | 830 ms |
| cold `open` of 333 files | 889 ms | 4858 ms |
| `diagnostics` of all 333 files | 943 ms | 3823 ms |
| peak RSS after cold load (`ru_maxrss`) | 222 MB | 377 MB |
| peak RSS after 150 broken mutations | 354 MB | 425 MB |
| peak RSS after the 200 edits (end of run) | 395 MB | 425 MB |

NameRef count on `src/` by `declNames` level (Task 3, Zend-run, 332 files): L0 42780 |
L1 45158 (+2378) | L2 52728 (+7570 over L1, +9948 over L0). The Workspace parses at level 2; the
extra 23% entries are the price of declaration-aware references/rename.

Verdict against G1: **p50 met natively (about 1 ms, also for broken files)**. The tail is not:
p95 18-28 ms and max 116-124 ms on the multi-thousand-line files. It tracks file size, and
`update` is almost entirely the parse (0.59 ms vs 0.57 ms for parsing alone); the rest is the
rules over the one file's AST. A faster tail needs an incremental parse (re-parse the edited
top-level declaration) or a cheaper walker, neither of which exists. P2b did not regress the
P2a numbers (p50 1.10 -> 1.07 ms, p95 22.5 -> 18.3 ms native; run-to-run noise is of that size).

Memory: `Workspace::open` keeps the AST of every open file (the benchmark opens all 333, so
the 222 MB native cold RSS is the worst case of "every file open"). `close` drops the AST and
text and keeps only the Index/NameIndex entries (the intended LSP shape); the closed-project
footprint is not measured. RSS growth over the mutations and edits (+173 MB native over cold)
is most likely un-returned AST memory; a longer soak was not run.

## Tooling

`tools/bench_semantic.php <dir> [--edits N] [--broken N] [--parity] [--cli-json FILE]`. Runs under Zend
directly (`php -d xdebug.mode=off -d memory_limit=2G tools/bench_semantic.php src --edits 200 --broken 50 --parity`). To
compile it natively, build a manifest whose `src` is a copy of `src/` without `main.php` and
whose `entry` is a copy of the bench (with `dirname(__DIR__)` replaced by the repo path), place
the binary so `../lib` is the repo's `lib/`, and `bin/manticore build -j 0 manifest.json`
(~2 min). `--cli-json` reads a saved `analyze --json` output instead of shelling out.
Native finding while writing it: the combination `sort($a); sort($b); $a !== $b` over two
`string[]` built in a loop and `array_diff` on them SIGSEGVs in the compiled binary (the
bench compares with `in_array` instead). Not minimised or filed here.

## What remains

Closed in P2b (no longer gaps): tolerant analysis and `ErrorMember`, `Diagnostic` end ranges,
declaration-name ranges and declaration-aware references, per-part interpolation spans (not
heredoc/nowdoc), anonymous-class naming, global constants in the Index.

**P2c**
- Parameters as names (declaration and use sites).
- Member use sites (`Suit::Hearts`, `->prop`): declarations are found, uses are not yet.
- `typeAt` and narrowing.

**P3** - document symbols and completion.
- Closed files: member use sites in a closed file resolve to nothing and are listed uncertain (`unresolved-receiver`), so a project-wide rename is uncertain everywhere outside open files. Root-cause fix: keep each indexed file's text (or re-read it from disk) and lazily reparse it, building `ExprAt` / `UnitLookup` on demand through a small LRU; never keep every AST.

**Open**
- Diagnostics for closed files (a closed file reports only the parse errors it had).
- Method return-type token range (needs a parser field; return-type findings span the method).
- `callable` / `SourceType` gaps still open.
- Heredoc/nowdoc interpolation parts share the whole-string range.
- Latency tail on multi-thousand-line files (see G1 verdict).
- `withDoc` has no production caller; `NameIndex::occurrenceAt` relies on NameRefs never overlapping;
  `UncertainSite` has no end position; docblock and `Invoke` uncertain sites are not implemented.
- `perform_analysis` (the CLI) still uses `Analyzer::run`, not the Workspace path; the CLI and
  Workspace winners differ for a name declared in two user files.
- Four `tests/analyze` cases fail before this work and still do: `flow_depth`, `json_smoke`,
  `type_mismatch`, `undefined_member`.
- `gate.sh` skips its php-dependent steps when php is absent.
