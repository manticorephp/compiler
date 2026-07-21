# Build pipeline, stdlib packaging & extensions

Status: **decision pending on #2** (this doc records the options + recommendation).
Companion to `docs/design/module-system.md` (the `manticore.json` / `.sig` spec).

Context: the compiler self-builds via `manticore build manticore.json` to a
byte-identical fixpoint. The compiler app is **self-contained** (stdlib embedded,
no second object) — that is what closed the manifest fixpoint (`11145f8`). What
remains open is how **user programs** get the stdlib.

## Background: the two-object corruption (rc=139) — ROOT-CAUSED

A binary linked from two manticore objects (user `.o` + prebuilt
`lib/manticore_stdlib.o`, the app excluding `src/Runtime`) **deterministically
SIGSEGVs** when built under very heavy allocation (e.g. compiling the whole
compiler that way). A single-object (self-contained) binary builds the same
workload fine.

**Root cause (found, definitive): `class_id` is a per-module sequential index,
but it is baked into object headers AND used for cross-object drop dispatch.**

- `LowerFromAst.php:247` assigns `class_id` as `$nextClassId = 1` incremented per
  class in scan order — **module-local, not a global identity**.
- The id is stored at object-header offset 0, and `@__mir_drop_dispatch`
  (`EmitLlvmRuntime.php` `dropRuntime`) is a `switch (class_id)` →
  `@__mir_drop_<id>` that releases *that class's* property layout. Its cases =
  only the classes known to the emitting module.
- So `class_id 9` = the 9th class in user.o (a compiler class) but the 9th class
  in stdlib.o (a Runtime class) — **different classes, same id, different layout
  + different drop body**. The `linkonce_odr @__mir_drop_dispatch` symbol is thus
  defined with DIVERGENT switch tables in the two objects (verified: 45/46 shared
  runtime helpers are byte-identical; only `__mir_drop_dispatch` differs —
  stdlib has `case 9 → @__mir_drop_9`, user does not). At `-O2` each object
  inlines its own table; an object allocated on one side and dropped on the other
  releases the wrong offsets → heap corruption, proportional to cross-object
  drops (maximal on the full compiler build).

Not the arena-state split (those globals are already coalesced `linkonce_odr`;
`nm` shows one `weak external ___mir_arena_cur`). `formatFloat` was hardened
(`11145f8`) so the *first* symptom (a corrupted float → invalid `inf.0` IR → hard
clang fail) became a valid-IR cosmetic INF; the underlying corruption then
surfaces as the rc=139.

## #4 — unify the build entrypoint

Goal: `manticore build manticore.json` is the single build definition; the bash
scripts stop hand-rolling clang/stub/link steps.

- **Done (`6292b94`):** `bin/build` self-host path now runs
  `manticore build manticore.json` (temp output via a one-line manifest `sed`,
  then smoke-test + atomic swap). `tools/selfhost.sh` is no longer the driver.
- **Done (`ef115f4`):** the cold seed `bin/compile` is now a *full self-rebuild*
  seed — Zend compiles `src/` to a THROWAWAY seed binary, which then runs
  `build manticore.json` to produce the shipped native compiler **and** the
  stdlib library. The manifest declares the stdlib as a library target
  (`src/Runtime → lib/manticore_stdlib.{o,sig}`); the self-contained compiler
  app opts out of linking it via `"libraries": []`. `cmd_build` gained per-app
  library selection + `--libs-only`. The bespoke find-glob + `[5/5]` step is
  gone; the manifest is the single build definition, shared by `bin/compile`
  and `bin/build`.
- **Why `cmd_build` can't itself run under Zend (so the seed stays a separate
  Zend stage):** `read_file` fills a `calloc` buffer via `fread`, and Zend
  strings are immutable; the fopen chain returns `\Ffi\Ptr` (an int-address
  wrapper), not a PHP resource. The Zend bootstrap of the *first* binary is
  therefore irreducible.

## #2 — how user programs get the stdlib (DECISION PENDING)

Measured (this session, debug-ish build, arm64):

- Tiny user compile, current two-object link: **0.54 s**.
- Lower + `-O2` clang of all of `src/Runtime` (`--emit-library`): **0.35 s**,
  → a 36.8 KB `stdlib.o`.

Key constraint discovered: **single-object cannot be object-cached.** clang must
process user + stdlib together every compile, so the +0.35 s is the floor for
that path. A build cache only helps the *separate-object* path (which is the one
with rc=139). So this is a genuine fork, not "single-module + cache".

### Option 2A — single-module (RECOMMENDED)

`compile` merges the bundled stdlib into the **user module** → one `.o`.

- ✅ rc=139 gone at every scale (proven: self-contained builds the whole
  compiler fine).
- ✅ Simple: a driver change — stop emitting declare-only externs + linking
  `stdlib.o`; instead include the stdlib definitions in the module (auto-discover
  `src/Runtime`, or a cached **lowered IR** fragment to skip re-lowering — note
  this saves lowering, NOT the clang pass).
- ➖ +~0.35 s per compile (0.54 → ~0.9 s on a tiny program). Negligible for
  CI/batch; noticeable but tolerable in a tight edit-compile-run loop.
- ➖ No object-level cache possible.

### Option 2C — fixed two-object (now that the root cause is known)

Keep the cached `stdlib.o` (≈0 overhead) but **give classes a globally-stable
drop identity** so `class_id` can compose across objects. What 2C really needs:

- **Drop via pointer, not id-switch.** Store a pointer to the class's drop
  function (or a class descriptor holding it) in the object header; `release`
  calls `(*hdr.drop)(obj)`. Each drop fn is `linkonce_odr @__mir_drop_<mangled
  FQN>` — the linker coalesces by the **real symbol name** (stable), so there is
  no central switch, no sequential numbering, no divergent ODR body. Composes to
  N objects (stdlib + extensions + user) by construction.
- Weaker alternative — stable id = `FNV(FQN)` + a complete dispatch table —
  fails because `linkonce_odr` keeps ONE (incomplete) copy of the switch. The
  fn-pointer form is the principled fix.
- This is a runtime-ABI change to the object header + drop path (and removes
  `dropRuntime`'s central switch). Well-contained; a pure correctness
  improvement. Audit other cross-object uses of the sequential `class_id`
  (instanceof / method dispatch) while here.

- ✅ Fast (cached object, no stdlib re-clang); unlocks extensions-as-objects.
- ⚠️ ABI change in the rc/drop path (the heisenbug-prone area) — do it carefully
  with the fixpoint + difftest gates.

### Recommendation — combined, sequential (2A → drop-ABI fix → fast 2C)

The two are complementary, not either/or:

1. **Now: 2A** (single-module). Correctness-first: rc=139 gone today at every
   scale, zero ABI change, +0.35 s/compile. Buys time to do the ABI fix right.
2. **Next: the drop-ABI fix** (fn-pointer / descriptor drop; kill the
   sequential-id central switch). This is what 2C actually needs. Once classes
   have a globally-stable drop identity, multi-object linking composes correctly
   → re-enable a cached `stdlib.o` (and extensions-as-objects) to recover 2C's
   speed WITHOUT rc=139. Optionally add the content-addressed object cache
   (`~/.manticore/cache`, keyed by srchash + compiler ABI + triple).

So 2A is the safe immediate correctness; the drop-ABI fix is the architectural
unlock for fast multi-object (2C) + extensions. After it, the default can be
fast two-object again.

If only 2A ships (drop-ABI fix deferred), `bin/compile`'s `[5/5]` step and
`lib/manticore_stdlib.{o,sig}` are deleted and #4's seed-unification falls out
cleanly (no two-object path left).

## Extensions (curl / xml / pdo, …) — MVP shipped

**Status:** the manifest-driven mechanism works (proof: the `zlib` extension
binding `crc32`, gated by `tools/ext_smoke.sh`). `extensions[]` in the manifest
declares `{ src, link, static }`; an app opts in via `"extensions": [...]`;
`cmd_build` compiles the glue into the app module and appends `-l<lib>` to the
link. See `docs/modules.md`. Remaining: static-archive linking (dynamic `-l`
today), and richer real extensions (curl/xml/pdo) on top of the same mechanism.

An extension = thin PHP glue + FFI bindings + a native library. The native lib
(libcurl/libxml2/libpq) is an ordinary C archive linked by `cc`; it never
touches the manticore arena/rc runtime, so it **adds no rc=139 surface**.

- Compile each opted-in extension's (thin) glue **into the user module** (same
  single-object model as 2A) — never as a fleet of separate runtime-sharing
  objects.
- **Opt-in** per program (small binaries — don't link libcurl unless `curl` is
  used). Declared in `manticore.json`:

  ```json
  {
    "applications": [{ "src": "src", "extensions": ["curl"] }],
    "extensions": {
      "curl": { "src": "ext/curl", "link": ["curl"], "static": true }
    }
  }
  ```

- **Static native libs by default** (preserve fully-static binaries, CLAUDE.md
  "no runtime dependencies"); dynamic (`-lcurl`) optional for large libs.

So the same single-module decision (2A) serves stdlib and extensions uniformly.

## Open items after #2

- ✅ Unify the Zend cold seed onto the manifest (#4) — done (`ef115f4`,
  full self-rebuild seed; per-app library selection + `--libs-only`).
- Weak library symbols (`--emit-library` exports `weak`) so an app can override.
- ✅ Composer autoload discovery — done. A `build` application target with
  `"composer": true` builds the project the way Composer sees it: its
  `composer.json` autoload (psr-4/psr-0/classmap dirs) AND every installed
  package from `composer.lock` (`vendor/<name>/`). The object form
  `{ "vendor": false }` restricts it to the project's own autoload. `files` /
  single-file classmap entries are deferred. Still open: `dependencies` install
  (there is no `composer install` step; a populated `vendor/` is presumed) and
  Packagist packaging (shipped separately as `install.sh` + `composer.json`).
- Cross-library `.sig` classes; element-type precision in `.sig`.
- ✅ The drop-ABI fix (fn-pointer drop) — DONE (`6e4d1ee`). Object slot 0 holds
  a per-class descriptor `{class_id, drop_fn}` (linkonce_odr); release calls
  drop_fn indirectly, so drops compose across separately-linked objects. The
  residual cross-object drop leak is gone → a cached multi-object `stdlib.o`
  (2C) and extensions-as-objects now compose by construction. (Chosen over a
  drop_fn header slot, which shifted properties 16→24 and bisected to
  non-deterministic self-build corruption.)
