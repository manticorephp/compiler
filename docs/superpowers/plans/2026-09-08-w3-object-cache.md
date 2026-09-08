# W3 step 1 — a content-addressed object cache

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or
> superpowers:executing-plans.

**Goal:** stop re-running `clang` over IR it has already compiled.

**Spec:** [`docs/design/backend-and-build-strategy.md`](../../design/backend-and-build-strategy.md) §4 W3.

## Why this shape, and not per-file separate compilation

The strategy doc's W3 asks for a module `.o` cache keyed on source + dependency `.sig`s. That
key cannot be built here: an application is ONE whole-program module by construction, and the
analyses that make the output fast are whole-program too — monomorphization, closed-world class
tables, and (since W2) the dynamic-dispatch candidate sets, which enumerate every function in
the program. Per-source-file objects would mean giving those up.

What IS available is that emission is **deterministic** — the fixpoint gate proves it every time
it reports two byte-identical generations — so an object is a pure function of
`(IR text, clang flags, clang build)`. That makes a content-addressed cache correct by
construction, with no dependency graph to get wrong.

**The measurement that decided the design** (`tools/prof/irdiff.php`, new): a one-method edit in
`src/` moves **24 of 6924 definitions — 0.35% of them, 0.91% of the bytes**. And the 24 are not
mysterious: they are the functions BELOW the edit in the same file, whose `__mir_bt_push` line
numbers shifted. So the IR of an edited program is ~99% identical to the IR of the program
before the edit, and the only question is whether the PARTITION preserves that.

## What landed

- `obj_cache_enabled()` / `obj_cache_dir()` / `obj_cache_key()` / `obj_cache_get()` /
  `obj_cache_put()` in `src/Manticore/Main.php`, wired into all four assemble paths (in-memory
  serial, in-memory split, staged serial, staged split — a manifest build uses the staged ones).
  Key = `sha1(flags | clang --version | sha1_file(part IR))`. Store is write-temp-then-rename.
- `SplitModule::$stable` — assign each shared definition to `crc32(symbol) % parts` instead of
  by load. The balancing partitioner prices each placement against what a part would have to
  copy, which is better for ONE build and fatal for a cache: one body growing re-shuffles every
  part. Stable mode turns on automatically when the cache is on.
- Off by default: `MANTICORE_OBJ_CACHE=1` to enable, `MANTICORE_OBJ_CACHE_DIR` to relocate
  (default `${MANTICORE_HOME:-~/.manticore}/cache/obj`).

## Measured (macOS arm64, the compiler's own module, 69.4 MB of IR)

| build | wall | clang | cache |
|---|---|---|---|
| unsplit, no cache (today's default) | 79-80 s | ~52 s | — |
| unsplit, cache warm, nothing changed | **27 s** | 0 s | hit (whole module) |
| 16 parts, cold | 56 s | 27.5 s | 0/16 |
| 16 parts, one-method edit | 49 s | 20.8 s | 4/16 |
| **64 parts, one-method edit** | **38 s** | **8.4 s** | **44/64** |
| 64 parts + ThinLTO, cold | 53 s | 10.3 s + 14.2 s link | 0/64 |
| 64 parts + ThinLTO, one-method edit | 45 s | 3.2 s + 13.6 s link | 44/64 |

Part count is the lever: 24 changed definitions can invalidate at most 24 parts, so with 16
parts they invalidate most of the module and with 64 they invalidate a third.

**The floor is the front end.** A warm build is ~28-30 s of PHP→IR and almost nothing else.
Caching clang cannot go below that; the next lever is front-end work, not more parts.

⚠ **A split build is an ITERATION artifact, not a shippable one.** Measured on the produced
compiler (`analyze src`, 3 runs): unsplit 0.937 s, 64 parts **1.686 s (+80%)**, 64 parts +
ThinLTO 1.236 s (+32%). A part boundary is an inlining boundary and ThinLTO only partly buys it
back at this part count. Ship unsplit; iterate split.

Verified: a binary linked from 44 cached objects + 20 fresh ones compiles and runs hello world
and passes 323 suite cases (`dyn`, `array`, `str`, `closure`).

## Next

1. **The front end is now the wall** (~29 s of a 38 s edit-rebuild). A per-function inference
   memo keyed on inputs is the standing idea (~5.9 s of a 69 s build, per the older note).
2. A cache eviction policy — today the directory only grows (18 MB per whole-module entry,
   ~40 MB per 64-part generation).
3. Wire `--fast` (branch `ci`) to turn this on: `-O1`, split, cache, no LTO is the loop.
