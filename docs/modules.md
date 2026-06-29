# Building projects with Manticore — the module system

Manticore builds multi-file PHP projects from a single cargo-style manifest,
`manticore.json`. One manifest defines every artifact — your application
binaries and any libraries they link — and the same definition drives both a
normal build and the compiler's own self-rebuild.

This is an end-user guide. For the internal design rationale see
`docs/design/build-and-packaging.md`.

---

## The manifest

`manticore.json` at your project root declares two kinds of target:

```json
{
  "libraries": [{
    "name": "util",
    "src": "src/Util",
    "output": "lib/util.o",
    "exclude": []
  }],
  "applications": [{
    "name": "app",
    "src": "src",
    "output": "bin/app",
    "entry": "src/main.php",
    "exclude": ["src/Util"],
    "libraries": ["util"]
  }]
}
```

Build everything:

```bash
manticore build              # uses ./manticore.json
manticore build path/to.json # explicit manifest
manticore build --libs-only  # build only the library targets, skip apps
```

`build` compiles every `libraries[]` entry first (each → a standalone `.o`
plus an `.o.sig` interface), then every `applications[]` entry (→ an
executable that imports its dependencies' `.sig` and links their `.o`).

### Application fields

| Field | Meaning |
|-------|---------|
| `name` | label, shown in build output |
| `src` | directory scanned recursively for `*.php` |
| `output` | path of the linked executable |
| `entry` | *optional* — the file whose **top-level code** becomes `main()`. Every other file contributes only its declarations. Omit it and files load in `find … | sort` order (a `zzz_*` driver sorts last by convention). |
| `exclude` | *optional* — path prefixes to skip in the `src` scan (e.g. a subtree compiled separately as a library) |
| `libraries` | *optional* — which **user** library targets to import + link. **Omit ⇒ all, `[]` ⇒ none**, a named subset ⇒ just those. Independent of the stdlib (see below). |
| `stdlib` | *optional* — set `false` to opt OUT of the always-on stdlib runtime. Only the self-contained compiler (which embeds `src/Runtime`) needs this. |

### Library fields

`name`, `src`, `output`, `exclude` — same as above. A library is compiled with
`--emit-library`: a standalone `.o` with **no `main()`**, plus `<output>.sig`.
A library marked `"runtime": true` is the bundled stdlib — built, but treated as
the always-on runtime rather than a normal dependency (see below).

### Why `"libraries": []` matters

An application that embeds a copy of a library's source in its own `src` must
**opt out** of linking that library, or the same symbols are defined twice. The
compiler itself does exactly this — it bundles `src/Runtime` (the stdlib)
directly, so its app target sets `"libraries": []`:

```json
{
  "libraries": [{ "name": "stdlib", "src": "src/Runtime",
                  "output": "lib/manticore_stdlib.o", "exclude": [] }],
  "applications": [{ "name": "compiler", "src": "src", "output": "bin/manticore",
                     "entry": "src/zzz_entry.php", "libraries": [] }]
}
```

The `stdlib` library still builds (for *user* programs to link); the compiler
just doesn't link it into itself.

---

## Module interfaces (`.sig`)

When a library builds, Manticore writes `<output>.sig` next to the `.o` — a
JSON table of the library's exported symbols (global functions, including
namespaced and FFI bindings). A dependent target imports the `.sig` to **type
and resolve cross-unit calls without re-parsing the dependency's source**.

This is what lets a *distributed* compiler ship only `bin/` + `lib/` (the
`.o` + `.sig`, no PHP sources) and still type-check and link user programs
against the bundled stdlib. You can inspect a `.sig` with:

```bash
manticore dump-sig src/Util/*.php
```

---

## How a user program gets the stdlib

The stdlib is **always on**. Whether you `compile` a single file or `build` a
manifest, every program transparently has the bundled stdlib available —
`str_pad`, `floor`, `array_reverse`, `json_decode`, `ctype_*`,
`file_get_contents`, … — with **zero manifest ceremony**. You never list it.

```bash
manticore compile app.php -o app          # str_pad() just works
manticore build manticore.json            # …and so does every app target
```

How it works: the compiler locates the bundled stdlib interface
`lib/manticore_stdlib.o.sig` (relative to the `manticore` binary, or via
`MANTICORE_STDLIB_SIG` / `MANTICORE_STDLIB_O`), injects externs for the stdlib
functions your code references, and links `lib/manticore_stdlib.o` at the final
step **only when you actually call one** — a program that touches no stdlib
function links nothing extra. The binary stays fully static (stdlib.o + libc).

The stdlib is **independent of the `libraries` selection**: an app that depends
on specific user libraries (`"libraries": ["mylib"]`) still gets the stdlib. The
only way to opt out is an explicit `"stdlib": false` — which only the
self-contained compiler uses, because it already embeds `src/Runtime` and would
otherwise double-define it.

Note there are two tiers of standard functions. A core set (`strlen`, `substr`,
`count`, `floor`, `sqrt`, `printf`, …) are **codegen builtins** — emitted inline
as IR / LLVM intrinsics, always present, no link. The rest are **PHP stdlib**
(`src/Runtime/Stdlib/*.php`), compiled once into `manticore_stdlib.o` and reached
as above. Both are transparent to you — call the function, it works.

---

## Building the compiler itself (bootstrap)

The compiler is written in PHP, so the first binary must be seeded by a stock
PHP interpreter; after that it builds itself from the manifest.

```bash
bin/compile         # COLD seed: PHP (Zend) builds a throwaway seed binary,
                    # which then runs `build manticore.json` → native bin/manticore
                    # + lib/manticore_stdlib.{o,sig}
bin/build         # SELF-HOST: the existing bin/manticore builds the manifest
                    # to a temp path, smoke-tests it, then atomically swaps in
bin/build --seed  # force the cold seed even if a binary exists
bin/build --verify# rebuild, then run the fixpoint + suite gate
```

`bin/compile` and `bin/build` both end by running `build manticore.json` —
the manifest is the single source of truth. Only the *first* binary needs the
Zend interpreter (the manifest build itself can't run under Zend: its file IO
fills mutable libc buffers that Zend's immutable strings can't provide). The
emitted binaries make no PHP-runtime calls — they link against libc only.

A self-rebuild is byte-identical: gen2 and gen3 emit the same IR.

---

## Extensions (native libraries)

An **extension** binds a native C library (zlib, libcurl, …) into your program:
thin PHP glue — an FFI binding plus a wrapper — compiled into the application,
and the native library linked by `cc`. The native lib never touches Manticore's
arena/rc heap, so it adds no runtime-corruption surface.

Declare extensions once under the manifest's top-level `extensions`, then opt
each application in by name:

```json
{
  "extensions": {
    "zlib": { "src": "ext/zlib", "link": ["z"], "static": false }
  },
  "applications": [{
    "name": "app", "src": "src/app", "output": "bin/app",
    "entry": "src/app/main.php", "extensions": ["zlib"]
  }]
}
```

| Extension field | Meaning |
|-----------------|---------|
| `src` | directory of the extension's PHP glue (`*.php`), compiled into every app that opts in |
| `link` | native libraries to link — each becomes `-l<name>` (so `"z"` → `-lz`) |
| `static` | reserved for static-archive linking (dynamic `-l` today) |

Opt-in is per application (`"extensions": [...]`), so a binary links libcurl
only if it actually uses the `curl` extension — small programs stay small.

### Writing the glue

The glue is ordinary PHP with FFI attributes. Bind the C symbol, then wrap it:

```php
<?php
use Ffi\Library;
use Ffi\Symbol;

/** uLong crc32(uLong crc, const Bytef *buf, uInt len) */
#[Library('z'), Symbol('crc32')]
function __ffi_crc32(int $crc, string $buf, int $len): int { return 0; }

function ext_zlib_crc32(string $s): int {
    return __ffi_crc32(0, $s, strlen($s));
}
```

`#[Symbol('crc32')]` makes `__ffi_crc32` a thin extern forwarder — its PHP body
is ignored; the compiler emits a direct call to the C symbol. Param/return
types map to the C ABI (`string` → `ptr`, `int` → `i64`, …). The full example
plus a build gate lives in `ext/zlib*` and `tools/ext_smoke.sh`. **For the FFI
binding mechanism in full — type mapping, `\Ffi\Ptr` handles, the
body-is-ignored model, memory across the boundary — see [`docs/ffi.md`](ffi.md).**

## Distributing a compiler

A built compiler is self-contained in two directories:

```
bin/manticore                  the native compiler
lib/manticore_stdlib.o         prebuilt stdlib object
lib/manticore_stdlib.o.sig     its interface
```

Ship those — no PHP sources required. Users run
`manticore compile their.php -o their` and link the bundled stdlib by `.sig`
automatically.

---

## Worked example

```
myproj/
  manticore.json
  src/
    main.php          // <?php  echo greet("world"), "\n";
    Util/
      greet.php       // <?php  function greet(string $n): string { return "hi $n"; }
```

```json
{
  "libraries":    [{ "name": "util", "src": "src/Util", "output": "lib/util.o", "exclude": [] }],
  "applications": [{ "name": "app",  "src": "src", "output": "bin/app",
                     "entry": "src/main.php", "exclude": ["src/Util"], "libraries": ["util"] }]
}
```

```bash
manticore build      # → lib/util.o (+ .sig) and bin/app
./bin/app            # hi world
```

`util` builds to a standalone object; `app` excludes `src/Util` from its own
scan, imports `lib/util.o.sig` to resolve `greet`, and links `lib/util.o`.
