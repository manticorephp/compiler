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
After the element-channel, slot-producer, by-ref and unlock steps: P1–P7 and
`w4_cell_arith` promoted; open = `w4_array_identity` (array `===`/`==` compare
words — a separate comparison epic, not a channel).

**Unlock (step 4, first half).** `arithType`'s `false &&` is gone — a plain
cell operand takes the tagged helpers, and the helpers decide int-or-float
from a numeric STRING too. What it exposed, and the last producer of the
element channel: an ERASED element read (`vec[unknown]`, a bare `array`
param, an unknown base) typed `unknown` carried the raw word — the sort
family's rebuild copied it into a fresh hint-0 buffer and handed it back under
`vec[cell]`. It is now typed CELL in InferTypes (`inferArrayAccess`,
`inferForeach`) and decoded at the read; the `sset()` witness that sank the
first decode is fine because the type now says cell and the string return
unboxes. The by-ref capture widen runs again after the closure-capture
convergence (a closure's params are first typed cell there).

⛔ **NOT MERGED — the unlock commit breaks the THIRD generation.** gen1 and
gen2 build and the suite is 1091/0/1093, but gen2 (the first binary whose own
code was emitted with erased-element cells) SIGBUSes on `dump-mir` of a
large source and on building src: the cycle collector drops a `Lexer\Token`
whose `kind` field is the `TokenKind::Variable` LITERAL + 1 (`"ariable"`, an
interior pointer of an immortal string; the release writes its rc into
rodata). A parser-only driver compiled by the same gen2 (lex + parse the
same file, the prelude, gc in between) does NOT reproduce it — the shape
needs lowering. Bisected: the erased→cell retype alone reproduces it; the
unlock alone makes gen2 unable to compile hello world; either half without
the other is worse. A CC_TRACE-baked build cannot be used to find it: the
traced gen1 traces every rc op of its own run and hits any log cap before
pass 1 ends (and once filled the disk). Second day (2026-09-21): the collector walker got a VERIFY guard
(`MANTICORE_DEBUG_VERIFY=1` — an obj-typed slot holding a non-object aborts
with class id, offset, word, parent), and gen2 built with
`MANTICORE_DEBUG_VERIFY=1 MANTICORE_AUTO_GC=2` (collect every 2 allocations)
aborts on `echo 1` inside `UnifiedArrayRuntime::emitAlloc` /
`EmitLlvm::emitFunction`: a `Codegen\Llvm\Value` whose `type` is a freed
`Type`, a `Compile\Mir\Concat` whose `left` is a freed `StringConst`, a
`FunctionDecl` slot — always a FIELD that lost the reference it should own,
never an rc<=0 release (the rc verify stays silent), so some path stores an
object into an obj-typed field WITHOUT the +1 or releases a borrowed one.
User-program models of the shapes (the IR builder loop with erased `array
$args`/`$indices`, `Value::int(Type::i64(), …)`, `foreach ($tokens as
$tok)`, the token filter, `array_walk` accumulators) compiled by the SAME
gen1 with verify + threshold 2 run clean — the defect is in a shape the
compiler's own module takes and the corpus does not. Recipe:
`MANTICORE_DEBUG_VERIFY=1 MANTICORE_AUTO_GC=2 bin/manticore build --apps-only
<manifest to scratch>` then `lldb -b -o run -k "bt 12"` on `compile echo1.php`.
Next: log every object free with its class (a capped `rcfree` trace filtered
to Value/Type) between the last clean collection and the abort, and match
the freed Type against the Value's creation site.

**Verifier (step 3).** `MANTICORE_CELLGUARD=strict` fails the build on any
`raw -> cell` edge the emitter-seam census sees (`EmitLlvmCellGuard`,
`Main.php` refuses to write the object). The ratchet baseline
(`tools/cellguard_baseline.txt`, `tools/cellguard_scan.sh --ratchet`, now
parallel) is the debt list; the flag defaults on when it is empty.
`plausiblePtrIr` → assertions is still owed.

**By-ref (P5/P6).** A local handed to a `mixed &` param is one word two frames
share and the callee may make it ANY kind, so the caller's slot is a cell for
that name (`InferScans::scanRefCellArgWiden`, the same table the by-ref
CAPTURE widening uses). Three things stood in the way, each a static claim
over a shared word: `scanCallSiteRefParams` narrowed a `mixed &` param to the
call sites' type even when the body ASSIGNS it whole (now excluded); the
float-slot seeding (`$v = (float)$v` on one branch) typed a cell param's first
read float; and a by-ref ELEMENT with a cell key (`retype($arr[$i])` under a
cell-array foreach) had no address path (`__mir_array_ref_slot_cell`). A store
through the cell param boxes arrays FLAT and objects by pointer (retaining a
borrow), and the native `json_encode` walker now decodes elements by the
buffer hint like every other cell reader. `settype` is a pure stdlib body over
`mixed &$var`.

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

## The element channel — the design both earlier attempts lacked

Every producer above except P5/P6 is an ARRAY in a cell channel, and the reason
arrays "ride raw by design" is that a boxed array pointer says nothing about its
ELEMENTS. The flags word already carries an element-kind hint nibble
(`ARRAY_ELEM_HINT_*`, bits 4-6) and a decoder (`__mir_box_by_repr`), but the
nibble has no code for a raw INT, FLOAT or BOOL (hint 0 = "raw scalar", decoded
AS IS), so a cell reader of a `vec[int]` element gets a tag-0 word. The read-side
decode was built twice (2026-07-29/30) and withdrawn twice, for two reasons that
are the design, not the bugs:

1. an UNKNOWN-typed element result was handed to consumers that deref it raw
   (`sset()` returning `$x["k"]` into a string slot) — decoding it invented a
   representation the static type never promised;
2. a CELL result written back into a raw-hinted buffer (the sort family's
   decorate → rebuild → write-back) left tagged words under a raw static type,
   because the de-cellify at the store is driven by STATIC types.

Both are the same rule violated once per direction: **the buffer's hint is the
truth at every erased boundary, and both the read and the store consult it.**

- **Hint codes** `INT=5<<4`, `FLOAT=6<<4`, `BOOL=7<<4` (the three free values).
  Every concrete-element store and literal stamps its code
  (`elementHintCodeForType`); `__mir_box_by_repr` becomes TOTAL — hint 0 is
  then only an EMPTY buffer, and the verifier counts any tag-0 word it decodes.
- **Read decode, only where the result type is CELL.** `emitArrayAccessUnified`
  / `emitErasedIndexGet` / foreach value / cursor reads decode via the hint when
  the static result is cell. An UNKNOWN result is never decoded; instead
  InferTypes retypes an erased element read as CELL (the channel is honestly a
  cell once the decode exists), so reason 1 disappears by construction and the
  consumers unbox by type as they do for every other cell.
- **Store encode, the mirror.** A boxed store goes through
  `__mir_elem_encode(arr, cell)`: hint CELL ⇒ the cell; hint 0 ⇒ stamp CELL,
  the cell; any raw hint ⇒ `__mir_array_cellify_inplace` (every element boxed
  by the old hint, hint → CELL, an ownership repr re-encoded, never
  introduced), then the cell. **Never a raw payload into a raw buffer** — the
  first draft did that, and it would have obliged every cell-typed reader
  (cursor family, walkers, merges) to decode; a buffer is raw-hinted exactly as
  long as no cell-typed store has touched it. A RAW store is the other mirror:
  `__mir_elem_encode_raw(arr, raw, kind)` boxes by the value's static kind when
  the buffer is CELL-hinted, and `__mir_elem_stamp_raw` records the raw
  hint/repr only when it is not. The sort family's write-back is then correct
  by the same rule that makes the read correct — reason 2 is gone.
- **The static claim is re-established where a buffer comes BACK.** A concrete
  array lvalue passed by-ref to an erased/cell param (`usort(array &$arr)`)
  gets `__mir_array_conform(arr, kind)` after the call: a CELL-hinted buffer is
  unboxed in place to the caller's kind (`__mir_cell_to_kind`), hint and repr
  follow. The rebuild (`emitAssocToCellArrayUnified`) reads the buffer's hint
  first for the same reason: `natsort($v)` had already cellified the caller's
  `assoc[string,string]`, and boxing those words as pointers double-tagged them
  (the natsort SIGSEGV of both earlier attempts). ⛔ Method/static by-ref calls
  do not conform yet — only the plain-call site does.
- **Bodies keyed by result type.** The erased index-get helper is one shared
  body per key channel; a CELL result decodes, an UNKNOWN one must not (`sset()`
  returns `$x["k"]` into a string slot), so the decoding body carries a `c`
  suffix in its name.
- **Not done: the flat `box_array` for every array.** The rebuild stays for a
  raw-hinted buffer crossing into a cell channel; retiring it needs every
  cell-base reader (cursor family, spread, union, comparisons, the runtime
  walkers) to decode by hint. Deferred behind the verifier.

**Slot producers (P1–P3), landed the same day.** A `$GLOBALS`-viewed global
cell and a `mixed`/unhinted static slot hold an ARRAY boxed FLAT
(`box_array`, the buffer's own hint intact — `boxForViewSlot`) and an object
by pointer; the `global $x` local reads the raw pointer back through the view
unbox, the element by-ref slot (`__mir_array_ref_slot`) works on an unboxed
scratch word re-boxed into the cell, and `vecWriteBack` re-boxes. Never the
cell rebuild at a store: it consumes a fresh source that the assignment
EXPRESSION still yields (`self::$d ?? self::$d = [...]` read a freed buffer).
An unhinted static (`public static $x`) is now a `mixed` slot whose read is
typed CELL; a bare `array`-hinted static stays an erased ARRAY slot (raw
pointer, element type refined by InferScans) — the two used to share
KIND_UNKNOWN. A static default array literal is typed from its own elements
and boxed like the scalar defaults. The scalar half of the sound untag
(`__mir_elem_untag_kind`) covers an INT/FLOAT/BOOL claim over a buffer a cell
writer cellified, paid only where another writer can reach the buffer (a
global cell, a by-ref binding). ⛔ `++`/`--` and a raw by-ref address on a
viewed global still hit the cell raw; `public $u` (unhinted INSTANCE prop)
still stores an array raw; array `===` compares words (`w4_array_identity`).

Landed 2026-09-20 (`w4`): suite 1080/0/1082 on gen2; P4 and P7 promoted;
`compare_natsort`, `erased_record_element`, `assoc_string_by_value_cow`,
`closure_env_lifetime` and `cond_own_objarray` were the five regressions the
first drafts hit, each one of the rules above.

Cost: one `load flags; and; br` per cell-typed element access and per element
store (raw stores pay the CELL-hint check too), plus one conform walk per
by-ref call into an erased param.

## Order

0. **Hint-complete elements.** The codes and the total decoder; the read decode
   at CELL results together with the store encode in ONE commit (both halves
   or neither); then the InferTypes retype of the erased element read. P4 and
   P7 are its witnesses.
1. **P1 + P2 + P3 — the SLOT producers.** One shape: the store and the read of
   one storage decided by different predicates. Fix = one repr per storage,
   decided once (the declared/lowered type), every store boxes to it (flat
   `box_array` for an array), every read trusts it. P3 falls out of P2.
2. **P5 → P6.** By-ref repr agreement, then `settype` as a stdlib body over it.
3. **Verifier.** `MANTICORE_TYPECHECK=1` hardened into a pass that fails the
   build on a `raw → cell` edge; cellguard's ratchet baseline (288 sites)
   driven to 0, then the flag defaults on.
4. **Unlock.** Delete the `false &&` in `arithType`; convert `plausiblePtrIr`
   sites to assertions; `MemoryAbi::VERSION` bump ⇒ one `bin/build --seed`.

Every step: `tools/w4_repros.sh` + `tests/aot/run.sh -j 0` + difftest before
merge; a repr change shows only in the generation AFTER the one that emits it,
so two self-builds before believing a green.
