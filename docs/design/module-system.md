# Manticore module system & distribution — design

Status: **PARTLY SHIPPED.** The `.sig` module interface and
`manticore build manticore.json` (library + application targets, cross-target
linking) are in the tree — user guide: `docs/modules.md`. Still unbuilt, and
absent from `src/` entirely: transitive dependency resolution, the global build
cache (`MANTICORE_HOME`, `~/.manticore/cache`), the `compiler_abi` field, and
the composer packaging bootstrap.

⚠ The `.sig` example below is aspirational. The emitter (`Manticore\Sig`) writes
exactly `{"schema":1,"functions":[…]}` — no `package`, `version`, `compiler_abi`,
`target`, `classes` or `constants`. That functions-only limit is a tracked epic
in `docs/ROADMAP.md`.

Goal: cargo/go/swift-class module system. Seamless, config-level deps
(composer.json-style). Easy to distribute the compiler, libraries, and
compiled programs.

## Mental model

| Layer            | Rust          | Swift              | Go            | **Manticore**                  |
|------------------|---------------|--------------------|---------------|--------------------------------|
| manifest         | Cargo.toml    | Package.swift      | go.mod        | **manticore.json**             |
| module interface | crate metadata| `.swiftinterface`  | export data   | **`.sig` (JSON)**              |
| code artifact    | .rlib         | .o/.a              | .a            | **`.o`**                       |
| package manager  | cargo/crates  | SwiftPM            | go/VCS        | **composer/packagist (fetch)** |

composer only fetches + resolves versions. `manticore` compiles + links.
The runtime preamble is `linkonce_odr` in every `.o`, so there is NO separate
runtime library to ship/link — compiled binaries are fully static.

## Locked decisions

1. **Lib distribution: source + OPTIONAL prebuilt.** Default ships PHP source +
   manticore.json (portable, cargo-style); a package MAY also ship prebuilt
   `.o`+`.sig` for popular target-triples (Swift binary-framework style). The
   compiler prefers a matching prebuilt, else builds from source into cache.
2. **`.sig` format: JSON** (reuse json_encode/json_decode; readable, extensible).
3. **Build cache: global `~/.manticore/cache/`**, keyed by
   `(source-hash, compiler_abi, target-triple)` — Go-module-cache style, shared
   across projects, auto-rebuild on any key change. `MANTICORE_HOME` overrides.

## `.sig` — module interface

A `.sig` is the serialized EXPORTED symbol table of a library target — the
same data `LowerFromAst` builds in `fnDecls`/`fnAliasByBare`, so a consumer
hydrates it and resolves calls IDENTICALLY to having the source in-module.

```json
{
  "schema": 1,
  "package": "manticorephp/stdlib",
  "version": "1.0.0",
  "compiler_abi": "mir-1",
  "target": "any",                       // or e.g. "arm64-apple-darwin" for a prebuilt
  "functions": [
    { "name": "str_starts_with", "symbol": "manticore_str_starts_with",
      "params": [ {"name":"haystack","type":"string"}, {"name":"needle","type":"string"} ],
      "ret": "bool" },
    { "name": "trim", "symbol": "manticore_trim",
      "params": [ {"name":"s","type":"string"},
                  {"name":"mask","type":"string","default":{"k":"str","v":" \t\n\r\\0"}} ],
      "ret": "string" },
    { "name": "array_keys", "symbol": "manticore_array_keys",
      "params": [ {"name":"arr","type":"array"} ],
      "ret": "vec<int|string>" },                       // element type → fixes typing loss
    { "name": "Runtime\\Libc\\strncmp", "symbol": "manticore_Runtime_Libc_strncmp",
      "params": [ {"name":"a","type":"string"}, {"name":"b","type":"string"}, {"name":"n","type":"int"} ],
      "ret": "int" }                                    // namespaced/FFI exported too
  ],
  "libs":  ["pcre2-8", "ssl", "crypto"],  // #[Ffi\Library] of the wrappers in here
  "weak":  ["signalfd", "__errno_location"], // extern_weak symbols it declared
  "classes": [],     // v2: cross-lib classes (layout + method sigs)
  "constants": []    // v2
}
```

Rules (these are exactly what unblocks the compiler self-build):
- **Export EVERYTHING**, incl. namespaced/FFI bindings (`Runtime\Libc\*`).
  The consumer rebuilds `fnAliasByBare` from them, so a bare `strncmp` resolves
  to `Runtime\Libc\strncmp` again. `symbol` stored explicitly so the mangling
  never diverges.
- **Types** carry element info (`vec<int|string>`, `assoc<string>`, `obj:Foo`,
  `cell`). Codec mirrors `Type` ↔ string.
- **Defaults** are const-folded at lib-build time (PHP defaults are constant
  exprs) and stored as a literal `{k,v}`; the consumer reconstructs a literal
  node for default-fill. No expr parsing on the consumer side.
- Per-param `byref` / `variadic` / tagged(cell) flags for the call ABI.
- **`libs` / `weak` are the library's LINK requirements**, not part of its call
  interface. They ride here because linking is a whole-program property while an
  FFI wrapper is emitted exactly once — in the module owning the source. A
  program calling `preg_match` gets the pcre2 wrapper out of the stdlib `.o` and
  has no `#[Ffi\Library]` of its own to derive `-lpcre2-8` from. Both keys are
  additive: a reader that finds them absent falls back to the old unconditional
  set rather than linking nothing.
- `compiler_abi` mismatch ⇒ rebuild from source (never link a stale `.o`).

## manticore.json

```json
{
  "name": "acme/app",
  "dependencies": { "manticorephp/stdlib": "^1.0", "acme/json-fast": "^2.1" },
  "libraries":   [ {"name":"foo","src":"lib","output":"...","exclude":[]} ],
  "applications":[ {"name":"app","src":"src","entry":"src/main.php","output":"bin/app","exclude":[]} ]
}
```
Versions/lock are composer's job (packagist constraints + composer.lock).

## `manticore build` flow

1. Resolve deps transitively (composer.lock pins versions).
2. For each dep: locate package (vendor/ or global), pick a matching prebuilt
   `.o`+`.sig` if present+compatible, else build from source into
   `~/.manticore/cache/<pkg>-<srchash>-<abi>-<triple>.{o,sig}`.
3. Application target:
   a. Load all dep `.sig` → merged imported symbol table.
   b. `LowerFromAst` hydrates `fnDecls` + `fnAliasByBare` from it and emits
      declare-only externs (`FunctionDef::isExtern`).
   c. Compile app sources → `app.o`.
   d. `cc app.o <dep1>.o <dep2>.o … -o bin/app`.
4. `linkonce_odr` dedups the runtime preamble across all objects.

### Sharp edge to fix: qualified call vs builtin short-name
`emitBuiltin` strips the namespace, so a FQ call `\Runtime\Libc\strlen` is
hijacked by the `strlen` codegen builtin. Rule: a call that resolves to an
IMPORTED FQN symbol takes precedence over the builtin short-name. Disable
builtin interception for qualified calls that have an import.

### Symbol conflicts between deps
Two libs exporting `trim` → exported lib symbols are `weak`, app definition
(strong) wins; lib-vs-lib conflict resolves by manifest dep order with a
warning (or hard error — TBD at impl).

## Distribution

- **Compiled programs**: static single binary (runtime linked in). Ship the
  file. No runtime install on the target.
- **Libraries**: source via packagist (built locally to cache); optional
  prebuilt `.o`+`.sig` per triple.
- **Compiler** (`manticorephp/compiler`): shipped as PHP SOURCE (manticore is
  self-hosting PHP). `composer global require manticorephp/compiler` →
  post-install hook bootstraps the native binary via the Zend seed
  (`php bin/compile`) using the user's PHP, and emits the bundled
  `stdlib.o`+`stdlib.sig` for the host triple. Chicken-and-egg solved by the
  system php.

### On-disk layout
```
~/.composer/vendor/manticorephp/compiler/   bin/manticore  lib/stdlib.o  lib/stdlib.sig  manticore.json
~/.manticore/cache/                          <pkg>-<srchash>-<abi>-<triple>.{o,sig}
<project>/  manticore.json  composer.json  composer.lock
            vendor/acme/json-fast/   (source + manticore.json)
            bin/app                  (static)
```

## Implementation roadmap (incremental, gated)
1. **Type↔string codec** + `.sig` writer (lib build emits JSON `.sig`, incl.
   namespaced/FFI + const-folded defaults + element types).
2. **`.sig` reader** → hydrate `fnDecls`/`fnAliasByBare` + extern FunctionDefs
   from JSON instead of re-parsing sources. Fix qualified-call-vs-builtin edge.
3. **weak** linkage for exported lib symbols.
4. Wire `manticore build`: per-target `.sig` emit/consume + global cache keyed
   by (srchash, abi, triple). Validate the COMPILER self-build via manticore.json.
5. **deps resolution**: read `dependencies`, locate under vendor/ + global,
   transitive + composer.lock.
6. composer packaging: `manticorephp/compiler` post-install bootstrap.
7. Rewire `bin/compile`/`bin/rebuild` onto `manticore build`; full gate
   (suite, difftest, stability, fixpoint).

Already done (commit 34418d9): stdlib bundling (prebuilt stdlib.o, declare
externs, linkonce/internal preamble, link tail), native json_decode +
mixed/cell consumption, `manticore build` for normal apps, str_replace O(n²) fix.
```
