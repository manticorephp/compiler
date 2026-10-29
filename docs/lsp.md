# Language server (`manticore lsp`)

`manticore lsp` is a Language Server Protocol server over stdio (JSON-RPC, `Content-Length`
framing). It is built on the semantic core (`src/Semantic/`, see
[`design/semantic-core.md`](design/semantic-core.md)): the same name index, type inference and
analyzer the `analyze` command uses, so editor diagnostics and `manticore analyze` agree.

A VS Code client lives in [`../editors/vscode/`](../editors/vscode/README.md); any LSP client
works with the command `manticore lsp`.

## Supported methods

| Method | Notes |
|---|---|
| `initialize`, `initialized`, `shutdown`, `exit` | `rootUri` (or the first workspace folder) is scanned at startup |
| `textDocument/didOpen`, `didChange`, `didClose` | full text sync |
| `textDocument/publishDiagnostics` | pushed on open and change |
| `textDocument/hover` | members and expressions (types) |
| `textDocument/definition` | classes, functions, constants, members, variables |
| `textDocument/references` | classes, functions, members, variables; honours `includeDeclaration` |
| `textDocument/prepareRename`, `rename` | see [Rename](#rename) |
| `textDocument/documentSymbol` | hierarchical outline |
| `textDocument/completion` | members after `->` / `?->` / `::`, variables after `$`, global symbols |
| `$/cancelRequest` | accepted, ignored (the server is synchronous) |

Capabilities advertised: `textDocumentSync {openClose, change: 1}`, `hoverProvider`,
`definitionProvider`, `referencesProvider`, `renameProvider {prepareProvider}`,
`documentSymbolProvider`, `completionProvider`.

## Docblocks

Class names in the type-carrying tags of `/** */` docblocks (`@param`, `@return`, `@var`, `@throws`, `@property*`,
`@method`, `@mixin`, `@extends` / `@implements` / `@use`, `@template T of X`, and their `@psalm-` / `@phpstan-`
variants) are class references: definition, references, hover and rename treat them like a written class name,
including generics (`Foo<Bar>`), unions, `Foo[]`, `?Foo`, `class-string<Foo>`, and `\Fully\Qualified` names (rename edits
the last segment only). Names resolve with the file's namespace and `use` map; a `use X as Y` alias is never edited.
Built-in and pseudo types, `@template` / `@psalm-type` names, `array{...}` keys and prose lines are not indexed.
A lowercase word right after `@return` / `@param` is read as a type, as PHPStan does. Docblocks are found by text
(strings and plain comments skipped; a heredoc holding an apostrophe can hide one), and a file with several
`namespace` blocks resolves docblock names against the first.

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
  text really spells the old name are also returned as edits with a change annotation
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

- Full-text sync only; every change re-sends and re-parses the file.
- The server is synchronous: one request at a time, `$/cancelRequest` is accepted but ignored.
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
