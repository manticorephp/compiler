# cellguard — the cell-contract verifier

`Type::KIND_CELL` claims "boxed, self-describing". It is a static claim; nothing
at runtime guarantees it. cellguard is the instrument that makes the claim
checkable: a static census at the emitter seam, a runtime cross-check at the
slot reads, and a corpus-level ratchet so the set of known violations can only
shrink. It does NOT fix producers — that is the element-channel epic.

Code: `src/Compile/Mir/Passes/EmitLlvmCellGuard.php` (a trait on the `EmitLlvm`
host). Tool: `tools/cellguard_scan.sh`. Baseline: `tools/cellguard_baseline.txt`.
Untracked working notes (calibration history, task reports):
`docs/status/CELLGUARD-CENSUS-2026-09-08.md` and `.superpowers/sdd/2026-09-08-cellguard/`.

**Binding constraint: with both flags off, the emitted IR is byte-identical to
an emitter without the trait.** Every marking call writes only to compile-time
PHP arrays; the flag-off path does no bookkeeping at all (the flags are read once
per `emit()` into two bools and every mark is gated on them). Proven per change by
an rc+md5 sweep of `.ll` output over 130 corpus files and, at the binary level, by
the fix wave's own evidence: the new source (post-fix `src/`) rebuilding itself
byte-identically, gen1 == gen2.

## Flags

| flag | what it does | cost when off |
|---|---|---|
| `MANTICORE_CELLGUARD=1` | static census: every emitted SSA register is classified; a `raw` value into a `cell` sink logs `CELLGUARD raw->cell <sink> fn=<f> ord=<n> line=<l> node=<k>` to stderr; one `CELLGUARD summary boxed=… opaque=… probed=… raw=… unchecked=… violations=…` per module | none |
| `MANTICORE_CELL_ASSERT=1` | runtime cross-check: `call @__mir_assert_cell(word, site)` after each of the three cell SLOT READS (`emitLoadLocal`, `emitPropertyAccess`, `emitStaticProp`); prints `CELLASSERT site=<n> word=<v>` and RETURNS (never aborts); the site table is logged at the end of `emit()` as `CELLASSERTSITE id=<n> fn=<f> kind=<static kind> slot=<name> decl=<declared type>` | none |

`bin/build` unsets both before invoking the compiler: an exported flag would bake
assert calls into `bin/manticore` and `lib/*.o`.

## The lattice

Each emitted register carries one provenance; default is `raw`.

| provenance | meaning | at a cell sink |
|---|---|---|
| `boxed` | came out of a boxing helper (`boxToCell`, `boxLastByRepr`, `boxRawValue`, a direct `@__manticore_box_*` call, and ~10 builtins proven to box on every path) | fine |
| `opaque` | loaded from a slot already typed `cell`; returned by a callee whose signature says `cell`; an element read from an array whose element type is `cell`; `emitCellifyArrayRaw`'s result (a raw array POINTER whose elements are cells — opaque, not boxed) | not checkable here — counted as coverage |
| `probed` | came out of a RUNTIME bit-pattern probe: `__mir_box_unknown` (`boxUnknownShallowIr`) or `boxUnknownIfRaw`'s `select istag ? v : box_int(v)`. Tag-valid by construction, VALUE unproven — a raw word whose bits spell a tag passes through unchanged | not checkable — counted, never a violation, never trusted as boxed |
| `raw` | anything else | **violation** |

A pass-through (`boxToCell` on an already-`cell` value, `array_key_first`-style
cursor reads of a cell element) TRANSMITS provenance; it never creates it. There
is no phi step: a phi's result is whatever the default says (`raw`) unless a
later mark claims it. A fifth counter, `unchecked`, is a SINK whose destination
slot type could not be determined statically (a dynamic-property store through a
runtime strcmp chain; element/property stores narrower than the emitter's own
box predicates) — counted so the census states its own blind spots.

Sinks checked: `store_local`, `store_element`, `store_property`,
`store_static_prop`, `return`, `call_arg`. `store_dyn_prop` is 100% `unchecked`.

## Known blind spots — read before trusting a number

1. **Floats are stored untagged.** `__manticore_box_float` returns the raw
   double bits; `__manticore_is_tagged` is `ugt 0xFFF0…`. So every legitimate
   float in a `mixed` slot fires `CELLASSERT` exactly like a missing box
   (`public mixed $p = 1.5` → `CELLASSERT word=0x3FF8000000000000`). The assert
   cannot tell a raw word from a double; only the static side can. That is why
   the `CELLASSERTSITE` table carries `kind`/`slot`/`decl` — a reader excludes
   float-shaped slots post hoc. Consequence for the runtime findings: the
   formatter/serializer/timer functions (`__mir_var_dump`, `__mir_print_r_str`,
   `__mir_var_export`, `__mc_ser_val`, `Async\TaskGroup__deadlineAt`,
   `Io\Poll\Context____waitKqueue`) are "likely float, unconfirmed", NOT proven
   blind spots.
2. **The `$GLOBALS` two-view slot is unmeasured by both instruments.**
   `emitLoadLocal` returns at `:401`/`:411` for a `globalBacked` local, before
   the `:452` mark + assert. An array in a `$GLOBALS` slot — the leading named
   producer candidate — has zero data in either direction.
3. **`opaque` element reads have no runtime corroboration.** ~4362 of the
   `opaque` occurrences are `emitArrayAccessUnified` reads marked on the array's
   declared element type; `emitCellAssert` is not wired there. Static reasoning
   alone.
4. **192 corpus cases (187 pre-merge) do not compile under the Zend host** (pre-existing
   `DependencyIndex::asStaticProp` rc=70) and have UNKNOWN status — excluded from
   every count, never counted as clean.
5. **`probed` hides the arithmetic producer.** The canonical `array_sum` /
   `array_product` `return` is `probed`: the accumulator short-circuits to
   `unknown` at `InferTypes.php:1731` (`$sum + $v` with an `unknown` operand),
   and the return boxes through `boxUnknownIfRaw`. The instrument now reports
   that RETURN honestly as "probed, not proven"; the producer is upstream in the
   arithmetic (`docs/design/unknown-cell-soundness.md` §18, the `false &&` gate at
   `InferTypes.php:1701`), not at the return.

## Current numbers (full corpus, `tools/cellguard_scan.sh`, 2026-09-22)

- Corpus: 1147 attempted, 0 compile failures, 1147 classified.
- **Occurrences: `raw`=2. Distinct `(sink, fn, ord)` sites: 2** — both the
  by-reference closure return of `byref_closure_bound_scope` (the ref-return
  channel, [value-channels.md](value-channels.md)).
- Coverage: `boxed`=14150 `opaque`=55652 `probed`=2 `unchecked`=1472.
- `MANTICORE_CELLGUARD=strict` is the default under `bin/build`: the compiler
  and the stdlib build with zero violations. Every violation line carries
  `src=<node>(<callee>):<type>` — bucket by it before reading a site.
- ⚠ The first post-W4 census (2026-09-22, before the instrument was fixed)
  said 3250 sites / 23313 raw; 98% was the instrument's own blind spots
  (value-channels.md, step 3). Read a jump in the numbers as a question about
  the instrument first.

## Numbers of 2026-09-13 (historical)

- Corpus: 1074 attempted (main's merge added 8 cases, e.g. `stdlib_zlib`, `stdlib_openssl_x509`), 192 pre-existing rc=70 compile failures (was 187), 882 classified.
- **Occurrences: `raw`=937. Distinct `(sink, fn, ord)` sites: 286.**
- Coverage: `boxed`=3691 `opaque`=12476 `probed`=75 `raw`=937
  `unchecked`=406.
- By sink kind (sites): store_property 94 · store_local 90 · return 63 · store_static_prop 19 · store_element 11 · call_arg 9.
- **Head of the work list** (distinct cases reaching the site): the Reflection cluster at 22 cases each — 16 sites tied: nine getters (`ReflectionClass__getConstant`, `ReflectionClassConstant__getValue`, `ReflectionEnumBackedCase__getBackingValue`, `ReflectionFunction__invoke`/`__invokeArgs`, `ReflectionMethod__invoke`/`__invokeArgs`, `ReflectionParameter__getDefaultValue`, `ReflectionProperty__getValue`, all `return` ord 0), `class_implements`/`class_parents`/`ReflectionEnum__getBackingType` (`store_local`), and the four `ReflectionEnum{Backed,Unit}Case` `__construct`/`__mc_defaults` `store_property` sites. Then `__mc_ob_call`/`__mc_ob_handler_name` (13), `Fiber____construct` ×4 ords (12), the `__mc_curl_*` family (8). `array_sum`/`array_product` are absent — `probed`.

**Corpus-change caveat — read before comparing to 5c.** Task 5c's census ran on `10b3ce3`, BEFORE main `c7ea2c5` was merged in; this is the first census AFTER the merge. Main brought new corpus cases and new prelude/stdlib code, so the bucket total moved from 17183 (5c: 3613+12165+1000+405) to 17585 (3691+12476+937+406), +402 sink visits. That is the CORPUS growing, not the instrument counting more. Only the classification shifts are attributable to this wave: `raw` → `probed` for the two probes (`probed`=75 ≈ the 72 `array_sum`/`array_product` return occurrences plus 3). No pre-wave post-merge census exists (the ledger recorded "ratchet not re-run post-merge", 30 min), so there is no like-for-like baseline; the numbers above are the new zero, not a delta. **286 vs 366 sites is the KEY change, not sites closing**: under the old `(sink, fn, line)` key `ReflectionEnumBackedCase__getBackingValue`'s ONE `return` sat at six lines (1788, 1834, 1840, 2685, 2731, 2753 — six "sites"); under `(sink, fn, ord)` it is one row, `return\tReflectionEnumBackedCase__getBackingValue\t0`, with those same six lines now visible as the per-case `line` column. In the other direction `__main` grew from 82 collapsed rows to 111 per-case `__main@<case>` rows. 175 of the 286 are named-function sites.

History (older key, `(sink, fn, line)`): Task 5 — 5784 raw / 660 sites (mis-keyed);
5b — 1195 / 421; 5c — 1000 / 366. The line key inflated site counts (one prelude
site at up to six lines) and collapsed `__main` rows across cases, so the numbers
are not directly comparable to the ones above.

## The ratchet — how to read `--ratchet` output

```
bash tools/cellguard_scan.sh                 # census only, ~15 min under Zend
bash tools/cellguard_scan.sh --ratchet       # + fail (exit 1) on any NEW site
bash tools/cellguard_scan.sh --update-baseline [--force]
CELLGUARD_SUBSET=40 bash tools/cellguard_scan.sh --ratchet   # smoke, exit 0 expected
```

- Site key: `(sink, fn, ord)`. `ord` is the N-th cell sink checked in that
  function's emission — the same N in every module that contains the function.
  This holds because `cellSinkOrd` (like `$cellProv`) is saved and restored
  around every memoized first-use helper (a synthetic body built mid-function,
  on demand): a function's own sinks are numbered by its own emission order
  alone, regardless of which helpers were first-used inside it, and a helper's
  sinks are numbered — and attributed (`fn=`) — under the helper's own frame.
  `line`, unlike `ord`, is not stable across modules (prelude assembly is
  demand-driven; one source node lands at a different absolute line per case).
  `fn=__main` is keyed `__main@<case>`. `line` is printed as a representative
  for humans and is not part of the key.
- `NEW:` a site in the current run not in the baseline → **exit 1**. A new site
  is either a real regression or a new corpus case exercising an existing
  producer; both need a look. Accept with `--update-baseline --force` only after
  reading each one.
- `CLOSED:` a site in the baseline not in the current run → progress, never
  fatal. Trust it only from a FULL-corpus run; a `CELLGUARD_SUBSET` run reports
  most of the baseline as closed by construction (the script says so).
- `--update-baseline` refuses under `CELLGUARD_SUBSET` (no override), refuses
  when any site is NEW (override `--force`), and is mutually exclusive with
  `--ratchet`. Every `sort`/`comm` is `LC_ALL=C`; the committed file is in C order.
- Determinism: two independent full-corpus scans gave byte-identical site sets
  under the old key; the ordinal is a deterministic per-function counter, so the
  new key inherits that.

## What is deferred to the element-channel epic

Tasks 7 (remaining producers), 8 (turn tagged arithmetic on), 10
(`plausiblePtrIr` → assertions). The head of the census is the erased array
element channel (`__mir_box_by_repr`'s `asis` passthrough, `array_sum`'s
`unknown` accumulator) — built and withdrawn twice before; it needs its own
design pass, not a Task 7 instance.
