# Roadmap

Manticore compiles PHP 8.5 to standalone native binaries and compiles itself to a
byte-identical fixpoint. The Zend interpreter is the oracle: if `php` runs a program, the
native binary must print the same bytes.

**Where we are heading:** running real-world PHP applications — correctly, fast, and with
modest memory — and compiling them in reasonable time. Self-hosting was the first milestone;
it is now the floor.

`[x]` done · `[ ]` open · `[~]` partly done. Status is as of 2026-10-11 (release 0.14.0).
Open bugs are tracked as [GitHub issues](https://github.com/manticorephp/compiler/issues),
not here.

- [Where things stand](#where-things-stand)
- [1. Real-world applications](#1-real-world-applications)
- [2. Standard library coverage](#2-standard-library-coverage)
- [3. Compile time and memory](#3-compile-time-and-memory)
- [4. Runtime performance](#4-runtime-performance)
- [5. Correctness](#5-correctness)
- [6. Tooling, platforms and releases](#6-tooling-platforms-and-releases)
- [Not planned](#not-planned)
- [Further reading](#further-reading)

## Where things stand

- [x] Self-hosting: the compiler builds itself to a byte-identical fixpoint
- [x] Reproducible bootstrap from a pinned release (no PHP needed to build)
- [x] Linux (Debian, Alpine; arm64 and amd64) and macOS arm64, checked by CI on every push
- [x] Memory model: refcounting with copy-on-write, flow-sensitive ownership analysis,
      zero-cost exceptions with correct unwinding and destructor order
- [x] Language: classes, interfaces, traits, enums, closures, generators, attributes,
      first-class callables, readonly, match, dynamic calls and `new $cls`
- [x] Standard library highlights: full `array_*`, `ext/intl` (100%), `session`, `sockets`,
      `json`, `tokenizer`, `iconv`, `ctype`, most of `mbstring`, `curl`, `pdo_sqlite`
- [x] Beyond PHP (superset): structured async I/O, FFI, a module system, HTTP/1.1 server,
      WebSocket client and server, typed native containers (`Manticore\Ds`)
- [~] Overall PHP function coverage: **967 / 2135** functions, **169 / 327** classes
      (see [`builtins.md`](builtins.md))

## 1. Real-world applications

Third-party code is the quality bar. Each target has a runnable exit criterion.

| Target | Status | Done when |
|---|---|---|
| symfony/console app (`examples/symfony-console`) | [x] builds and runs | output matches php on its command set |
| php-cs-fixer (`@PSR12`, `@Symfony`) | [~] runs natively with a parallel worker pool; `@Symfony` output matches php | whole rule corpus matches php; speed competitive with native fixers |
| symfony-demo (full framework, ~79k functions) | [~] builds and runs to completion | no missing-function traps; pages answer like php |
| HTTP server benchmark workload | [~] 64k req/s on one process, 124k on eight workers | competitive with established PHP servers |
| A Laravel-class application | [ ] | needs MySQL/PostgreSQL drivers, `openssl`, XML (see §2) |
| Composer | [ ] | `composer install` on a real project |
| Static analyzers (PHPStan / Psalm class) | [ ] | analyzes its own source |
| PHPUnit running a real test suite | [ ] | same pass/fail list as php |

How it works: compile an application, collect every missing function and crash, and turn each
into a stdlib item (§2) or a bug report with a minimal repro that becomes a regression test.

## 2. Standard library coverage

Priority is how many real applications reach a function, not the percentage. A missing
function compiles and fails at run time with `Call to undefined function`.

**P0 — every application**
- [~] Core 92 % — remaining `get_defined_vars`, `get_class_vars` [#215] · [x] `SPL` 100 % [#214]
- [~] `password_*`, `crypt` — argon2, DES crypt open [#212] · [x] `hash` KDFs/copy/update_stream [#213]
- [ ] `openssl` ~40 % — slice 1 done (encrypt/decrypt, digest, sign/verify, pkey RSA/EC, rsa crypt, x509 read/parse, pbkdf2); csr/pkcs7/pkcs12/cms/spki/dh/seal remain [#211]
- [ ] PDO `pgsql` / `mysql` drivers [#210]
- [~] `standard` 82 % · `date` 84 % — tail tracked in [#218]
- [~] `Reflection` 62 % — interface reflection, declaring class, defaults of plain functions
- [~] `mbstring` 62 % — remaining: `mb_convert_variables`, `mb_parse_str`, `mb_http_*`,
      `mb_convert_kana`, `mb_strcut` on stateful encodings

**P1 — web frameworks**
- [ ] `xml` SAX parser [#216] · [x] `random` classes [#217] · `fileinfo` [#219] · `dom` 28 % [#220]
      · [x] `filter` [#221] · `posix` / `pcntl` [#222]
- [ ] `xmlwriter`, `xmlreader`, `libxml` loaders (see #225)
- [ ] `mysqli`, `pgsql` procedural APIs after the PDO drivers
- [x] `pdo_sqlite`, `curl`, `session`, `sockets`, `intl`, `iconv`, `ctype`, `tokenizer`

**P2 — common**
- [~] `zlib` 50 % (one-shot + incremental done over libz; `gz*` streams open) · [ ] `zip` [#225]
- [ ] `bcmath` · `gmp` [#223] · [ ] `sodium` + argon2 [#224] · [ ] `gd`

**P3 — on request:** `calendar`, `gettext`, `readline`, `bz2`, `ftp`, `ldap`, `soap`, `odbc`,
`snmp`, `tidy`, SysV IPC, `exif`, `dba`, `Phar`. `mb_ereg*` is parked (Oniguruma is EOL).

Ground rules: every function is checked against php output; extensions backed by a C library
link it only in programs that use it; where php warns and carries on, Manticore throws.

## 3. Compile time and memory

Reference point: compiling symfony-demo (79k functions, 1.1 GB of LLVM IR) takes about
33 minutes and 4.7 GiB at peak. The compiler rebuilds itself in 1–2 minutes.

- [x] Compiler self-build peak memory cut from 4.1 GB to ~0.5 GB (escape analysis by SCC)
- [x] Dynamic-name calls dispatch through a table instead of a comparison chain
      (−12 % IR, −31 % build time)
- [x] Content-addressed object cache for split builds
- [~] Emit less IR — `clang -O2` is about two thirds of build time; helpers instead of inlined
      loops is the proven lever
- [ ] Cut front-end re-inference (~29 s on the compiler itself)
- [ ] Bring the large-application peak under 2 GiB
- [ ] Real incremental builds via separately compiled libraries (`.sig` interfaces)
- [ ] Build progress output: stages, counters, timings ([#176](https://github.com/manticorephp/compiler/issues/176))

Optimization policy: `-O2` for anything shipped; `-O1` without LTO for the edit–build loop.

## 4. Runtime performance

Compute-heavy code already runs 2–50× faster than php 8.5; associative arrays beat php.

- [x] Typed native containers: `Manticore\Ds` arrays, Map, Set, Vec
- [x] Native JSON decoder with strict mode; `gzencode` 10× faster than the pure-PHP path
- [~] `SplFixedArray` on native buffers (typed access and iteration done; bounds-check
      hoisting open)
- [ ] JSON decode allocator
- [ ] Small-string interning; cheaper property-bag literals
- [ ] Replace pointer-plausibility guesses in erased channels with checked representations

## 5. Correctness

- [~] Open bugs: ~120, mostly silent wrong answers surfaced by compiling real applications;
      19 crashes, 9 leaks. Each has a minimal repro in `tests/aot/repro/`.
- [x] Ownership redesign: every value has one owner at each program point; generators,
      exceptions and `finally` release correctly
- [ ] Self-describing values in erased (`mixed`) channels — root of many open bugs
- [ ] References that agree with their target's representation
- [ ] Integer overflow promoting to float (needs value-range analysis)
- [ ] Visibility enforcement (`__get` on inaccessible members, `(array)` key mangling)
- [ ] `extract()`, `settype()`, `goto` into loops, `ReflectionEnum`

Known semantic gaps in detail: [`gaps.md`](gaps.md).

## 6. Tooling, platforms and releases

- [x] CI: Linux arm64/amd64 on Debian and Alpine, macOS arm64, weekly parity run against php
- [x] Release tarballs and container images built from the pinned bootstrap
- [ ] `manticore upgrade` ([#174](https://github.com/manticorephp/compiler/issues/174))
- [ ] Surface the ABI version in `manticore version`
- [ ] Dependency fetching and a shared module cache (design exists, not built)
- [ ] `dump-mir --after=<pass>`
- [ ] Automatic cycle collection (manual trigger only today)
- [ ] Windows

## Not planned

- A custom machine-code backend — LLVM is the healthiest component; the real costs are in
  the middle end ([rationale](design/backend-and-build-strategy.md)).
- C extensions, PECL, `php.ini`. Native libraries come in through FFI and manifest targets.
- Emulating Zend warnings: where php warns and continues, Manticore throws.

## Further reading

[`../README.md`](../README.md) · [`install.md`](install.md) · [`builtins.md`](builtins.md) ·
[`superset.md`](superset.md) · [`async.md`](async.md) · [`ffi.md`](ffi.md) ·
[`modules.md`](modules.md) · [`memory.md`](memory.md) · [`http.md`](http.md) ·
[`ds.md`](ds.md) · [`gaps.md`](gaps.md)

Design notes: [`design/mir.md`](design/mir.md) · [`design/memory-abi.md`](design/memory-abi.md) ·
[`design/module-system.md`](design/module-system.md) ·
[`design/stage0-bootstrap.md`](design/stage0-bootstrap.md)
