# Subclass-prop object union — handoff (deferred, 2026-06-30)

Re-apply ready: `git apply docs/deferred/subclass-prop-union.patch` (150 lines,
touches `InferTypes.php` + `EmitLlvmObjects.php`). It applies cleanly on
`67d27c0`.

## What it does (and it WORKS, functionally)
`subclassPropType` returns an **object union** of every subclass that declares
`$prop` (instead of an arbitrary first subclass), so a base-typed read of a
subclass-only property dispatches on the runtime class_id:

```php
abstract class Owner {}
class DogOwner extends Owner { public Dog $pet; }
class CatOwner extends Owner { public Cat $pet; }
function petSound(Owner $o) { return $o->pet->sound(); }   // $o->pet : Dog|Cat
```

Before: `$o->pet` typed `obj<Dog>` (first subclass) → `->sound()` binds
statically to `Dog::sound` → wrong. After: `$o->pet` typed `union<Dog|Cat>` →
class_id dispatch → correct. Pieces:
- `subclassPropType` → `Type::union($types)` when all arms are objects.
- `unionPropType($u,$prop)` — read type from the declaring atoms (kind-agree).
- `inferPropertyAccess` — a `KIND_UNION` receiver resolves via `unionPropType`.
- `emitUnionPropertyAccess` — offset-agreement fast path, else a class_id switch
  over the atoms' descendants (deduped) picking the per-atom offset.

Verification on the patch: **AOT suite passes (sp3 `woofmeowmoo`, differing-offset
`WS`), self-host BUILD succeeds.** The ONLY failure is the fixpoint gate.

## The blocker: self-host FIXPOINT non-convergence
`tools/selfhost_fixpoint.sh` → `FIXPOINT BROKEN: Stage-2 and Stage-3 IR differ`.
The trigger is the compiler's OWN AST: `Parser\Ast\Stmt->decl` is
`FunctionDecl` on FuncStmt and `ClassDecl` on ClassStmt, so `subclassPropType`
returns `union<FunctionDecl|ClassDecl>` (the SOLE union in the self-compile —
confirmed by a probe: exactly one `SUBUNION base=Stmt prop=decl` site).

### What the gen2-vs-gen3 diff showed (SMALL — ~10 regions)
ALL diffs are **large-int constants flipping boxed↔raw** in the compiler's own
code (MemoryAbi masks, `IntConst::__construct(PHP_INT_MAX)`, `int_to_str(<big>)`):

```
< %r34 = call i64 @...IntConst____construct(i64 %r32, i64 9223372036854775807, ...)   ; gen2 RAW
> %r34 = call i64 @...IntConst____construct(i64 %r32, i64 281474976710655, ...)        ; gen3 = PHP_INT_MAX & 0xFFFFFFFFFFFF (BOXED→48-bit)
```

So a large-int CONSTANT is typed `int` (raw) in gen2 but `cell` (boxed → the
48-bit payload mask, or now a bigint heap-box) in gen3 — a **type-flip**, same
class as the double-box, but it manifests as inference NON-DETERMINISM across
generations rather than a single-program bug. The union's presence on
`Stmt->decl` perturbs the compiler's self-inference so it does not reproduce
itself in one generation.

NOTE: this is INDEPENDENT of the bigint box (the diff was byte-identical before
and after `67d27c0`) and INDEPENDENT of the representation-consistency fix
(`69dc785`) — both are in `67d27c0` and the fixpoint still breaks. It is a third,
distinct mechanism: **inference convergence**.

## TOP HYPOTHESIS for the next session (cheap to test first)
The diff is TINY (a handful of constants), which smells like **slower
convergence, not oscillation** — the union compiler likely reaches a fixpoint at
**gen3 == gen4**, one generation later than the gate asserts (`gen2 == gen3`).
gen1 here is built by a NON-union compiler (the previous clean binary building
the union source), so gen1 carries non-union quirks; gen2 reflects gen1; they
may need one more generation to settle.

**TEST IT FIRST:** apply the patch, `bin/build`, then build gen2→gen3→gen4 by
hand (`dump-llvm-mir src` + assemble + link, mirroring selfhost_fixpoint.sh) and
`diff` gen3 vs gen4.
- **gen3 == gen4** ⇒ slower convergence. FIX is easy: re-seed one extra
  generation before the fixpoint check, or bootstrap from a union-built seed
  (`bin/build --seed` twice) so gen2 is already a true union compiler. Then the
  patch ships as-is.
- **gen3 != gen4** ⇒ genuine OSCILLATION. Then dig into which pass flips the
  constant's box decision (next section).

## If oscillation — where to dig
The flipping value is a large-int constant typed `int` vs `cell`. Find the pass
that boxes it differently when a union is in scope:
1. **ConstFold** — does it fold `box_*(PHP_INT_MAX)` to a masked constant? Does a
   union-typed sibling change a fold decision?
2. **Monomorphize** — a union arg/return may specialize differently each run;
   check if a union return type makes a specialization set non-deterministic.
3. **The InferTypes re-infer loops** (`scanCallSiteArrayElems` re-run,
   `hasUntypedAssocKeyStore` guard, `Monomorphize` re-infer) — does a union type
   make a convergence predicate oscillate (e.g. a union compared unequal to
   itself across a pass, or `unionWith` not idempotent)? **Check `Type::union`
   determinism**: atoms come from iterating `$this->classes`; confirm that order
   is identical across generations and that `union(union(a,b))==union(a,b)`
   (idempotent) so a re-infer pass doesn't keep widening.
4. The narrowest containment: gate `subclassPropType` to NOT union when `$base`
   is reached only through pinned (`asX`) reads — but the compiler can't see
   downstream pins, so prefer fixing convergence over gating.

## Repro recipe
```bash
git apply docs/deferred/subclass-prop-union.patch
bin/build                       # builds (union compiler)
bash tests/aot/run.sh           # passes (incl. sp3-style)
bash tools/selfhost_fixpoint.sh # FIXPOINT BROKEN  ← the target
# recover if needed:
git checkout -- src/Compile/Mir/Passes/InferTypes.php src/Compile/Mir/Passes/EmitLlvmObjects.php
bin/build --seed
```

## Done this session (all committed, fixpoint-green)
- `9289a5f` object-dispatch unions (ternary/array) — STABLE, the union machinery
  (`Type::union`, dispatch, `unionMethodReturn`, `boxToCell` union) lives here.
- `69dc785` representation consistency: mixed-element array props → cell element
  (fixes the double-box at the TYPE level).
- `67d27c0` full 64-bit ints in cells (heap big-ints) — unblocked by 69dc785.

See [[representation_consistency_root_2026_06_30]] and
[[strict_analyzer_epic_2026_06_29]].
