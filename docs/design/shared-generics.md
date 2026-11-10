# Shared generics

Every program used to send the whole prelude through clang -O2: 60–80 % of a
program's IR, the same bodies again and again. Now each large shareable body is
compiled once per **variant** and reused from the object cache by every later
program that emits the same text. Specialization is kept: a program that
specializes a prelude body in place simply emits another variant.

## Mechanism

`assemble_shared` (`src/Manticore/Main.php`) runs for an executable's module on
the single-unit path, unless `MANTICORE_SHARED_GENERICS=0`.

`Compile\Mir\ShareSplit` cuts the module into:

- **part 0, the program part** — the module with each cut body replaced by its
  `declare`, plus an `@llvm.compiler.used` pinning what the body parts need;
- **one body part per cut body** — written to depend on nothing but the body's
  own text, because the object cache keys a part by its bytes:
  - only the declarations the body names, sorted; no blank or comment lines;
  - a shared global as `external`; a file-local `unnamed_addr` constant copied;
  - a file-local function or constant promoted to `linkonce_odr` in the program
    part and named `external` in the body part;
  - `available_externally` copies of small callees (≤ 2048 B for compiler
    runtime helpers `__mir_`/`__mc_`/`__manticore_`, ≤ 512 B for prelude PHP,
    depth 2), never of a whole-program helper (`_mapp`), a `$mono$` clone or a
    closure;
  - the body pinned by its own `@llvm.compiler.used`.

A body is cut when it is `linkonce_odr`, ≥ 8192 B of IR, not a whole-program
helper (`_mapp`), and names no **file-local mutable** global. Such state is
never promoted: while a global is `internal` and its address stays in, LLVM
keeps it in registers across calls; promoting the arena/pool cursors cost the
string and JSON benches 6–19 %.

Parts compile through the existing object cache (`obj_cache_key`: flags, clang
id, exact `.ll` bytes); misses run as grouped clang invocations (one driver
over many inputs — clang costs ~42 ms per process before reading a byte).
The program part's key is prefixed `prog/`, so it ages on its own budget and a
cache shipped between machines can carry only the shared bodies.

## Invariants

- **Prelude code must not embed program-local facts in names or constants.**
  The executable's helpers take the fixed module token `app` (libraries keep a
  hashed token); prelude closures take ids derived from where they are written;
  prelude files are parsed one by one (line numbers do not depend on the
  injected set); by-name call ids are hashes of the name and the table length is
  read, not inlined. `tools/generic_identity.php` / `tests/generic/run.sh`
  measure this (identity and variant hits).
- A body part must be self-describing: anything that varies with the program
  and lands in a part's text turns every hit into a miss.
- Correctness never rests on normalization: reuse is by exact text.

## Measurements (macOS arm64, 2026-11-10)

138-case AOT sample, `bin/manticore compile`, sums:

| | wall | CPU |
|---|---|---|
| whole module (`MANTICORE_SHARED_GENERICS=0`) | 534 s | 454 s |
| empty cache | 475 s (89 %) | 476 s (105 %) |
| warm cache (same programs) | 244 s | 197 s (43 %) |

Variant hits across programs: 67 % of shareable-body bytes after the identity
fixes (17.5 % before). A cache seeded by 80 % of the sample serves 73 % of the
rest's body bytes on their first compile.

Runtime: 52 benches, each build linked app-first and stdlib-first (a layout
artifact decided `htmlspecialchars` otherwise), median of 11: geomean 1.000;
`json_deep` and `ksort_asort` +2–4 %, open.

## Knobs (measurement only)

`MANTICORE_SHARE_MIN`, `MANTICORE_SHARE_AVAIL`, `MANTICORE_SHARE_DEPTH`,
`MANTICORE_SHARED_GENERICS=0`; the cache itself: `MANTICORE_OBJ_CACHE_DIR`,
`MANTICORE_OBJ_CACHE_MAX_MB`. `manticore cache` shows where it is and how big; `manticore cache clear`
empties it (only the cache's own `<sha1>.o` files).

## Not yet

- Libraries and the staged/auto-split path of large apps keep their existing
  assembly.
- A seed of common variants shipped with a release (first-ever compiles).
