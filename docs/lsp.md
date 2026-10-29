# Language server (`manticore lsp`)

`manticore lsp` is a Language Server Protocol server over stdio (JSON-RPC, `Content-Length`
framing). It is built on the semantic core (`src/Semantic/`, see
[`design/semantic-core.md`](design/semantic-core.md)): the same name index, type inference and
analyzer the `analyze` command uses, so editor diagnostics and `manticore analyze` agree.

A VS Code client is developed in a separate repository; any LSP client
works with the command `manticore lsp`.

## Supported methods

| Method | Notes |
|---|---|
| `initialize`, `initialized`, `shutdown`, `exit` | `rootUri` (or the first workspace folder) is scanned at startup |
| `textDocument/didOpen`, `didChange`, `didClose` | incremental sync (UTF-16 ranges; a change without a range replaces the text) |
| `workspace/didChangeWatchedFiles` | created / changed / deleted `.php` files (a deleted directory too) refresh the index; registered dynamically for `**/*.php` when the client allows it |
| `textDocument/publishDiagnostics` | pushed on open and change |
| `textDocument/hover` | members, functions, variables and expressions (types; see Hover) |
| `textDocument/definition` | classes, functions, constants, members, variables |
| `textDocument/references` | classes, functions, members, variables; honours `includeDeclaration` |
| `textDocument/prepareRename`, `rename` | see [Rename](#rename) |
| `textDocument/documentSymbol` | hierarchical outline |
| `textDocument/completion` | members after `->` / `?->` / `::`, variables after `$`, global symbols |
| `$/cancelRequest` | honoured for queued requests and between files of a member query (error -32800) |

Capabilities advertised: `textDocumentSync {openClose, change: 1}`, `hoverProvider`,
`definitionProvider`, `referencesProvider`, `renameProvider {prepareProvider}`,
`documentSymbolProvider`, `completionProvider`.

## Hover

The signature goes in a `php` code block; array types add a short bullet list below it, then one line saying where
the type comes from.

- Declared types are the native hint merged with the docblock (`@var`, `@param`, `@return`, `@psalm-` / `@phpstan-`
  variants): a docblock refines a bare `array` / `iterable` (nullable too), any other native hint wins. Class names are
  fully qualified through the file's namespace and `use` map.
- Rendering: `list<V>`, `array<K, V>`, `array{id: int, name?: string}` (`?` = optional key; positional entries are
  keyed `0`, `1`, ...; a key that is not a plain word is single-quoted with `\\`, `'`, backtick and newline escaped), and `V[]` for the one-argument `array<V>` (also what `@var V[]` means).
- Variables: parameters show the declared type, locals the inferred type at that point (narrowing applies), foreach
  variables the element type. A literal with distinct string keys (at most 8) is a shape, other keyed literals are
  `array<K, V>`, elements of different types join into a union (`list<int|string>`), `[]` is a bare `array`.
- A string-literal key that PHP casts to an int (`'1'`, `'-5'`) or ends in `?` makes the literal a plain `array<K, V>`, not a shape.
- Source label: `*from annotation*` only while the shown type is the docblock-refined declared one; after a reassignment or
  narrowing, and for locals, `*inferred*`; a plain native declaration has no label. A docblock never overrides a native
  `int`/`string`/class hint, and a non-array docblock type is ignored on a native `array`.
- Markdown safety: code spans use a delimiter longer than any backtick run inside, and the signature fence grows past a
  ``` run in the text.
- Detail lines list `keys` / `values` (generic arrays) or one `key: type` per entry (shapes), nested arrays indented,
  at most 12 lines, `…` when cut. A method or function hover prefixes the list of its return type with `**Returns**`.

```php
App\A::$byName: array<string, App\Foo>
```
- keys: `string`
- values: `App\Foo`

*from annotation*

Constants show their native type only (the parser keeps no docblock on a constant). Hover never fails: any error
answers the plain type text.
## Docblocks

Class names in the type-carrying tags of `/** */` docblocks (`@param`, `@return`, `@var`, `@throws`, `@property*`,
`@method`, `@mixin`, `@extends` / `@implements` / `@use`, `@template T of X`, and their `@psalm-` / `@phpstan-`
variants) are class references: definition, references, hover and rename treat them like a written class name,
including generics (`Foo<Bar>`), unions, `Foo[]`, `?Foo`, `class-string<Foo>`, and `\Fully\Qualified` names (rename edits
the last segment only). Names resolve with the file's namespace and `use` map; a `use X as Y` alias is never edited.
Built-in and pseudo types, `@template` / `@psalm-type` names, `array{...}` keys and prose lines are not indexed.
A lone lowercase word right after `@return` / `@throws` followed by more words (`@return the thing`) is description, not a
type; a capitalised word (`@return A new instance`) still reads as a class, a known ceiling. Docblocks are found by text
(strings, plain comments, heredocs / nowdocs and inline HTML are skipped); the namespace and top-level `use` map in force at
each docblock are tracked from the same pass, so files with several `namespace` blocks resolve per block. If the PCRE scan
fails, a degraded linear scan of `/** */` blocks runs for files with a single `namespace` (it cannot tell strings from comments); files with several namespaces get no docblock names.

## Positions

LSP positions are 0-based lines and **UTF-16 code units**; the semantic core counts 1-based
lines and **bytes**. The server converts both ways on every request and every returned range,
so a line with multi-byte text (`"héllo"`, emoji) keeps hover, rename and diagnostics on the
right characters.

## Workspace indexing

At startup the server walks the workspace root and indexes every `*.php` file, **including
`vendor/`**, so go-to-definition and rename see library code. Limits:

- Files over **2 MiB** are skipped.
- `.gitignore` is not read; everything under the root is walked.
- Files created after startup are not in the index until opened.

Only open files carry a full AST. Closed files are searched from their text on demand (see
[Rename](#rename)).

## Diagnostics

The analyzer's findings for the changed file, pushed after each `didOpen` / `didChange` with
precise ranges (the same rule codes as `manticore analyze`, e.g. `undefined.function`,
`type.mismatch`). Parse errors come from the tolerant parser, so a half-typed file still gets
semantic diagnostics for the parts that parse. Dependents of an edited file are re-published.
Closed files report only the parse errors they had at scan time.

## Rename

`prepareRename` answers the range and placeholder, or `null` if the symbol cannot be renamed
(for example a library declaration). `rename` computes one edit set from references plus
declarations.

- **Blockers refuse.** Cases the engine cannot rewrite safely (unknown owner, library
  declaration, named-argument and trait-adaptation uses, a parse-error region) fail the request
  with an error naming the reason.
- **Uncertain sites are flagged.** Sites that may or may not refer to the symbol (a class name
  in a string, a dynamic member, a callable string, a docblock mention, an unresolved
  receiver) are listed in a `window/showMessage` warning. When the client declares
  `workspace.workspaceEdit.documentChanges` and `changeAnnotationSupport`, those sites whose
  text really spells the old name (a qualified string such as `'App\Foo'` matches by its last segment only; `'Foo\Bar'` never matches `Foo`) are also returned as edits with a change annotation
  (`uncertain:<reason>`, `needsConfirmation: true`), so VS Code's Refactor Preview shows them
  unticked for the user to decide; the edit then uses `documentChanges` with the open
  document's version (`null` for closed files). Certain edits carry no annotation. Uncertain
  sites whose text does not match are dropped. Other clients get `changes` with the certain
  edits only.
- **Members search the workspace files that mention them.** A member query temporarily opens
  every scanned closed file whose text contains the member name (case-insensitive) so its use
  sites resolve; other files are not parsed. Rename refuses with `rename incomplete: N workspace
  files could not be searched` when more than 5000 files would be opened, a file is over 2 MiB
  or unreadable, or the startup scan could not index a file (any rename kind, since class and
  function renames read the same index), rather than apply a partial edit.
- **Only the last name segment is edited.** `use App\Models\User;`, `new \App\Models\User()` and
  `new User()` become `...\Person`, `...\Person`, `Person`. A site spelled with an import alias
  (`use ... as U2; new U2()`) is left alone; the `use` line is renamed. Before editing, every
  site's text is checked against the symbol's name; if the file changed on disk since it was
  indexed, the rename fails with `index out of date for <file>` instead of editing wrong bytes.
- **Closing a file** re-reads it from disk into the index, so discarded edits do not leave stale
  positions; a closed file that no longer exists is dropped from the index.
- **Parameters:** renaming a parameter is refused while a named argument spelled like it exists
  in the workspace (`m(val: 2)`).
- **Sigils** (`$`) are decided per location from the source text, so renaming a property or
  static property never corrupts `$` and a leading `$` in the new name is stripped.

Known limit: an **interpolating** heredoc (a body containing `$name` or `{$`) anywhere in the
workspace blocks **all** renames (`Workspace::renameBlockers` reports `heredoc-interpolation`),
because interpolation parts inside heredocs share one range. Heredocs without interpolation do
not block.

## Known ceilings

- Every change re-parses the whole file (incremental sync saves transfer, not parsing).
- The server is single-threaded: one request at a time. `$/cancelRequest` stops a request still queued, or a member query between closed files; it cannot interrupt one parse or the final analysis step. No `didSave` handling: an open file is already current, and disk changes arrive as watched-file events.
- Locations inside the prelude / stdlib (`lib:` URIs) are not navigable.
- An **installed** binary needs `lib/` and the prelude next to it. A bare copy of
  `bin/manticore` elsewhere prints the `Workspace: the prelude is empty` error and exits 1.
  Symlinks to a real install work.
- Completion: a receiver on a previous line (multi-line fluent chain starting with `->`) yields
  no members; global names are fully qualified with no `use`-awareness; `__*` methods such as
  `parent::__construct` are not offered; keywords are not offered; capped at 200 items
  (`isIncomplete`).
- Member references are the slowest operation (below): they re-parse the closed files.
- Class, function and constant renames trust the startup index for closed files. A file that
  gained a reference after startup (git checkout, external edit) is not re-read, so that site is
  omitted. Stale sites already in the index are detected and refuse the rename; new ones are not.
  There is no `didChangeWatchedFiles` / `didSave` handling yet. Restart the server after a branch switch.
- Renaming a magic method (`__construct`, `__invoke`, …) or an interface method called implicitly
  (`offsetGet`, `current`, `count`, `getIterator`, …) edits the declaration and not the implicit
  callers; references miss them too. Do not rename these through the editor.

## Latency

`php -d xdebug.mode=off tools/bench_lsp.php` drives a real `bin/manticore lsp` over pipes with
`rootUri` = this repo's `src/` (362 files), one open file (`Semantic/ExprTyper.php`, 542
lines) and deterministic positions (seed 4242). Apple M1 Pro, 10 cores, macOS arm64, native
compiler build, 2026-10-29:

| Operation | n | p50 ms | p95 ms | max ms |
|---|---|---|---|---|
| hover | 200 | 0.1 | 0.4 | 1.2 |
| definition | 200 | 0.2 | 7.7 | 8.8 |
| references (class) | 30 | 8.0 | 8.9 | 33.7 |
| references (member) | 30 | 984.0 | 1859.4 | 1984.9 |
| completion after `->` | 200 | 4.5 | 5.2 | 6.1 |
| documentSymbol | 200 | 0.7 | 0.7 | 0.9 |
| didChange to diagnostics | 200 | 7.6 | 8.7 | 10.8 |

Startup scan of `src/`: 1.2 s. didOpen to first diagnostics: 8 ms. Server RSS: 123 MB after
the scan, 510 MB at the end (after the member-references passes, which parse every closed
file that mentions the member name; 60 member queries end at 633 MB, so growth flattens
rather than scaling per query). Linux numbers are not recorded.

Why RSS climbed, and the fix (issue #528): a member query used to open every closed file that
mentioned the name at once, and a tolerant AST is about 45x its source, so the in-query peak was the
AST set of all candidates (~510 MB under Zend; the native allocator kept that high-water mark
resident, 805 MB RSS after 60 queries versus 132 MB after the scan). `ClosedFiles::with` now hands the
workspace an opener (`Workspace::withClosedFiles`): a use site in a file that is not open is resolved
by opening that file, resolving all its same-named sites, caching only the resulting `MemberInfo`
lists for the query, and closing it before the next file. Only locations and member infos outlive a
file, never its AST. The cancellation check still runs between files, and the skipped/cap accounting is
unchanged. Measured under Zend over `src/` (60 member queries, `memory_get_usage` after a
`gc_collect_cycles`): 83 MB after the scan, 84 MB after the queries, 163 MB in-query peak (was ~510 MB).
Native (`tools/bench_lsp.php --nref 60 --n 50`, three runs, macOS arm64): RSS 126-128 MB after the scan, 421-516 MB at the end
(805 MB before the change). That is still ~3.3-4x the post-scan figure, not the 1.5x the issue asked for: the in-query peak is
now ~3x lower but the native allocator keeps its high-water mark resident and the compiler's known leak adds to it, so the
remaining growth is not a retained AST. #528 stays open for that.
