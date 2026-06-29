# 09 — File discovery, autoloading, and project config

How the Manticore compiler decides which `.php` files to parse and merge into one LLVM module, and how a project tells it where its sources live.

## Position

The compiler is **not a runtime with lazy `include`**. It is a static, whole-program tool: every class it sees is materialised at compile time, and every cross-file reference must resolve before code generation. There are no `include_once` calls in the emitted IR.

`require` / `include` / `include_once` / `require_once` in user source are treated as **compile-time hints** at most (today they are silently ignored — Manticore picks files up via discovery, not via runtime evaluation of the include statement). The "PSR-4 autoloader" pattern most PHP projects rely on collapses into "look at the right directory" because we already see every file before we lower the first instruction.

## Discovery in priority order

1. **Entry file**: `bin/compile entry.php` parses `entry.php` first. Its top-level statements become the binary's `main()`.
2. **Project config** (optional): `manticore.config.php` at the project root, if present, returns an array that overrides defaults. Plain PHP so the existing parser handles it — no new format, no extra dependency.
3. **PSR-4 namespace map**: every namespace prefix → directory mapping. Resolves a `use App\Foo\Bar;` reference to `src/App/Foo/Bar.php` (or whatever the config says).
4. **Convention default**: when no config is present, each top-level directory under `src/` is treated as a top-level namespace. `src/App/Foo.php` → class `App\Foo`. Matches the layout the self-host bootstrap already uses.
5. **Greedy mode**: for the self-host build (and any project that wants "compile everything"), `bin/compile` runs `find src -name "*.php" | sort | xargs` so every file lands in the merged compile regardless of whether the entry file actually references it. The trade-off is faster bootstrap iteration vs longer compile time; the entry-driven mode is the right default once compile time matters.

## `manticore.config.php` shape (design draft)

```php
<?php

return [
    // PSR-4 prefix → directory list. Each directory is relative to
    // the project root (the directory containing this file).
    'psr4' => [
        'App\\'     => ['src/'],
        'App\\Test' => ['tests/'],
        'Ffi\\'     => ['src/Ffi/'],
        'Runtime\\' => ['src/Runtime/'],
    ],

    // Files that must always be parsed even when no class references
    // them — top-level executable code that the entry file relies on
    // (driver bootstrap files, FFI declaration files with no class).
    'eager' => [
        'src/zzz_entry.php',
    ],

    // How aggressively to walk. 'entry' (default) = follow class
    // references transitively. 'greedy' = include everything under
    // every PSR-4 root. The self-host build uses 'greedy'.
    'discovery' => 'entry',

    // Optional: a static-method that returns extra files to compile
    // (plugin / extension lookup). The method receives the
    // already-discovered file list and returns either an array
    // mapping path → reason or `null` for no additions.
    'plugin' => null,
];
```

The compiler reads this once at startup. `manticore.config.php` is PHP — we already parse PHP. No TOML, no JSON, no YAML, no extra parser.

## Why not TOML / JSON / YAML

- TOML would need a parser we don't have.
- JSON would need our own parser too (libc has no JSON).
- YAML — even more.
- A PHP file returning an array uses the parser we already wrote, costs nothing extra, and gives users full PHP expressivity for any compile-time logic they need.

The Composer ecosystem uses `composer.json` but we have neither a runtime nor a composer install step — there is nothing to interop with that we don't already control. Convention-by-default + an optional PHP config file beats every other format on cost-to-implement.

## Resolver algorithm (entry-driven mode)

```
discover(entry):
    parsed = {}
    pending = [entry]
    while pending non-empty:
        f = pop(pending)
        if f in parsed: continue
        program = parse(f)
        parsed[f] = program
        for ref in collect_class_refs(program):
            file = psr4_resolve(ref)
            if file and file not in parsed:
                pending.push(file)
    return parsed.values()
```

`collect_class_refs` walks the AST looking for: `new X`, `extends X`, `implements X`, `use X` (trait), `X::method`, `X::$prop`, `X::CONST`, parameter / return type hints, `@var X` / `@param X` docblock hints, attributes.

`psr4_resolve(\Foo\Bar\Baz)`:
- Strip leading `\`
- For each registered prefix, longest-match wins. `Foo\Bar` matches before `Foo`.
- Substitute: prefix `Foo\\` → `src/Foo/` produces `src/Foo/Bar/Baz.php`.
- Return null if the file doesn't exist (lazy classes that resolve at runtime in Zend won't resolve here — explicit error).

## Greedy mode

For the self-host build the discovery cost (~milliseconds) is dwarfed by everything else, and we want every class in the merged compile regardless of reachability. Today `bin/compile` is hard-wired to greedy:

```bash
find src -name "*.php" | sort | xargs php tools/compile_files.php
```

This stays. The entry-driven discoverer below is what we add when external apps come online.

## Phasing plan

1. **Now**: document this design (this file). Greedy mode continues to work for self-host.
2. **Next**: implement `discover(entry)` in PHP. Lands behind a flag (`bin/compile --entry foo.php`).
3. **Then**: read `manticore.config.php` if present, override psr4 + eager + discovery.
4. **Later**: report unresolved references as compile errors with hints.
5. **Optional**: cache the resolved file list per-entry under `.manticore/cache/discovery/` so repeated builds skip the AST walk.

## Non-goals

- A Composer replacement. We do not install packages, do not fetch from the network, do not manage versions. Anyone using `composer require` upstream points Manticore at the resulting `vendor/` directory via `psr4` config; that's the interop story.
- Lazy / dynamic autoloading at runtime. The whole point is to not have one.
- Per-class file lookup that can fall back to PSR-0, file-per-class with magic, or PEAR-style name munging. PSR-4 + config is the surface.

## Open questions

- Cyclic class references between files — should `discover()` track which file references which, to support topological sort on emit? Today the compiler tolerates any order at emit time, so no.
- Multiple PSR-4 entries for the same prefix (e.g. `App\\` → `[src/, generated/]`). Resolver tries each in registration order; first hit wins. Settled.
- How does `eager` interact with `discovery: entry`? Eager files are added to the initial pending set; they still contribute their own references to the walk.
- Workspaces / monorepos: when a project root contains nested `manticore.config.php` files. Probably out-of-scope; users can run `bin/compile` per workspace.
