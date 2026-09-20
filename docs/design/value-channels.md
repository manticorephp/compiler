# W4 — self-describing value channels

_Branch `w4`, off main `b17ede4` (2026-09-20). The epic named in
[backend-and-build-strategy.md](backend-and-build-strategy.md) §W4; the work
cellguard deferred (its Tasks 7, 8, 10 — see [cellguard.md](cellguard.md))._

## The claim, and the one rule

A slot typed `cell` promises that every word it ever holds is a tagged cell
(`EmitLlvmExpr::cellTagIr`: INT=1 BOOL=2 NULL=3 PTR=4 BIGINT=5 FLOAT=synthetic
ARRAY=7 OBJECT=8 REF=9 — every PHP value HAS a tag, arrays included via
`MemoryAbi::CELL_ARRAY_TAG_BITS`). Today the promise is made by the type
system and kept by nobody: several PRODUCERS type a slot `cell` and store a RAW
word into it. The consumers that re-probe (`boxUnknown*`, `plausiblePtrIr`)
paper over it; the first consumer that TRUSTS the claim — tagged arithmetic —
fails, and is held back program-wide behind `false &&` in `arithType`.

**Rule:** a value crosses into a `cell` channel through ONE boxing step chosen
from the value's STATIC type at the producer, never a runtime probe, and never
"raw because it happens to be a pointer". A channel that cannot name its
producer's static type is not `cell`; it is `unknown`, and `unknown` has no
consumers that trust it.

Consequences the rule forces, each a former "by design" exception:
- an ARRAY in a cell slot is `CELL_ARRAY_TAG_BITS | ptr`, not the bare pointer
  (identity is the pointer inside the payload; nothing is rebuilt);
- a `$GLOBALS['x']` view and `global $x` are the SAME storage and must agree
  on repr — the view is cell, so the binding is cell;
- an unhinted static property is `mixed` and its READ is cell, like its STORE
  already is;
- a by-ref callee that may change the value's TYPE (`mixed &$v`) requires the
  caller's slot to be cell for that name, or the call re-boxes on return.

## Producers — the repro set is the exit gate

`bash tools/w4_repros.sh` compiles every `tests/aot/repro/w4/*.php` and diffs it
against the php oracle recorded beside it. A passing repro is promoted into
`tests/aot/cases/` + `expected/`. The epic exits when the directory is empty,
`arithType`'s `false &&` is gone, and `MANTICORE_TYPECHECK` is on by default.

Baseline on `b17ede4` (2026-09-20): 7 open, 3 already green and promoted.

| # | producer | repro | symptom today | root (status) |
|---|---|---|---|---|
| P1 | `$GLOBALS['x']` view of an ARRAY | `w4_globals_view_array` | read prints `float(2.1E-314)`; `$GLOBALS['x'][] = v` SIGSEGVs; `global $x` on the same storage is right | view is lowered cell, the binding types the storage from the cross-scope join; both read RAW and agree only for scalars. `unionTypes(cell,int)` = unknown so the join records nothing; `__main`'s seed store writes raw (KNOWN, [[erased-arith-epic-2026-08-07]]) |
| P2 | unhinted static prop (`public static $arr = []`) | `w4_static_prop_unhinted` | array read back as float; scalars fine | READ typed `unknown`, STORE boxes by the declared type; typing the read cell was tried and reverted because an ARRAY rode the slot raw (KNOWN, `staticPropRef` ⚠) |
| P3 | `public static mixed $a = [1,2]` default | `w4_static_prop_mixed_default` | default array read as float; `0`/`1.5`/`"s"`/`null`/`true` fine | the default initializer stores the array RAW into a cell slot (only scalars boxed) — same slot as P2 (TO VERIFY it is the initializer, not the read) |
| P4 | homogeneous literal into an `array<K,mixed>` param | `w4_lit_adopts_param_channel` | `Bag(['name'=>'bob'])` then `$x['name']` = `int(<addr>)`; add one int to the literal and it passes | `litBoxesValues()` reads the literal's OWN element type; a literal in ARGUMENT position must adopt the callee's element channel — INFERENCE, not the emitter; the release side (`litElemCollect`) already threads it (KNOWN, [[eidx-outline-2026-09-07]]) |
| P5 | by-ref write that CHANGES the type (`mixed &$v`) | `w4_byref_write_changes_type` | `$a=5; retype($a)` ⇒ `int(<addr>)`; string ⇒ `"5"`; float ⇒ denormal | callee OWNS the slot repr (`refPinnedLocals`), caller's local is typed by its first store; a `mixed &` param must force the caller's name to cell, or the emitter must write back through the caller's repr (TO VERIFY which) |
| P6 | `settype($v, …)` | `w4_settype` | `Call to undefined function settype()` | unimplemented; it IS P5 (a by-ref retype) plus the cast table — do after P5, pure stdlib body first |
| P7 | `$g[$i] = $c` on a cell base with a cell value | `w4_cell_base_elem_store` | last element `int(<addr>)` where `$c` came in as `mixed` "1" | the element store on a cell base takes the ARRAY store path with the raw word (KNOWN, [[zlib-pure-php-2026-09-08]]) |

Promoted as guards (green on `b17ede4`): `w4_erased_elem_foreach`
(`array_combine`/`array_map` element channel — the 2026-08 symptom is closed),
`w4_fiber_ctor`, `w4_reflection_returns` (the cellguard census HEAD — 16
Reflection `return` sites — prints correct values: those sites are `probed`
in effect, a census false positive to reclassify, not a producer).

## Order

1. **P1 + P2 + P3 — the SLOT producers.** One shape: the store and the read of
   one storage decided by different predicates. Fix = one repr per storage,
   decided once (the declared/lowered type), every store boxes to it, every
   read trusts it. P3 falls out of P2.
2. **P4 — inference.** Literal in argument position adopts the param element
   channel. Moves the monomorphization ladder ⇒ full suite + difftest.
3. **P5 → P6.** By-ref repr agreement, then `settype` as a stdlib body over it.
4. **P7.** Element store on a cell base.
5. **Verifier.** `MANTICORE_TYPECHECK=1` hardened into a pass that fails the
   build on a `raw → cell` edge; cellguard's ratchet baseline (288 sites)
   driven to 0, then the flag defaults on.
6. **Unlock.** Delete the `false &&` in `arithType`; convert `plausiblePtrIr`
   sites to assertions; `MemoryAbi::VERSION` bump ⇒ one `bin/build --seed`.

Every step: `tools/w4_repros.sh` + `tests/aot/run.sh -j 0` + difftest before
merge; a repr change shows only in the generation AFTER the one that emits it,
so two self-builds before believing a green.
