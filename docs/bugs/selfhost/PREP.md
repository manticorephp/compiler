# Self-host representation bugs — session prep

Surfaced while building PHP-shaped backtrace frames (`c238dc5`). Each was
*worked around* to ship the feature; the roots are open. This doc is the entry
point for a dedicated session.

Confirmed reproducers live next to this file and each prints wrong output under
`bin/manticore compile <f> && ./out` while `php <f>` is correct. Run one with:

```bash
bin/manticore compile docs/bugs/selfhost/bug3_int_substr_assoc.php -o /tmp/b3 && /tmp/b3
php docs/bugs/selfhost/bug3_int_substr_assoc.php     # reference
```

The two confirmed bugs almost certainly share a root: **array/assoc VALUE
representation is lost or mis-tagged in specific constructions** — the same
family as the deferred "untyped array residue" epic. Fix the representation and
both fall.

---

## BUG-3 — int value + substr-derived strings in one assoc literal  ★ HIGHEST
`bug3_int_substr_assoc.php` — CONFIRMED, standalone.

Building `["line" => $intLn, "function" => substr($n,$p+2), "class" => substr($n,0,$p)]`
inside a loop **scrambles the values** (a later iteration's data bleeds into an
earlier frame) or **SIGSEGVs**. A `(string)$intLn` cast also crashes. A
homogeneous all-string assoc compiles clean.

- Impact: forced the V1 backtrace frames to DROP `line` (kept int → crash). The
  proper fix restores `getTrace()[i]['line']` as a real int, matching PHP.
- Minimised: mixed value types + `substr` results + a loop + ≥4 keys. Removing
  the int key, or the substr, makes it pass (`bug3` has notes). A 2-key mixed
  assoc (int+string, no substr) works — so it's the *combination*.
- Hypothesis: the assoc's per-slot value tag is computed once (or shared) and the
  substr temporaries reuse a slot the int/next-iteration value also lands in.
  Look at how array/assoc literal element values are boxed/stored and whether a
  `substr` result temp is freed/aliased before the store completes.

## BUG-1 — bare `array` property erases its VALUE type  ★ known-family
`bug1_bare_array_prop_value_erasure.php` — CONFIRMED, standalone.

A bare `public array $d` (assoc string→string) reads its string VALUES back as
raw pointer ints when read untyped (`foreach ($this->d as $v)`). A typed getter
(`: string`) coerces and hides it; a LOCAL assoc of the same shape is fine — only
the PROPERTY erases.

- Workaround in use: `/** @var array<string,string> */` on the property (the
  compiler honours the `@var` value type). Applied to `Module::$methodDisplay`
  and the `EmitLlvm` mirror.
- This is the property-side of the same "value type erased on a bare array"
  gotcha already noted for element types (`@var T[]`). Extend the property
  element/value inference so a bare `array` property whose stores agree on a
  concrete value type keeps it — as already done for `$this->p[] = <val>`
  (`0eacfb6`), but here for assoc VALUES.

## BUG-2 — tail field-read drift inside a method body  ☂ murky, compiler-only
NOT minimally reproducible (see `/tmp` attempts in the session — a subclass tail
field read via a base-typed param, and a field read twice in a method, both pass).
Observed only inside `EmitLlvm::emitMethodCall`: reading `$mc->btClass` (a newly
added tail string field on `MethodCall_`) returned the ENCLOSING method's name,
and reading the SAME field twice (ternary condition + value) returned two
different values. A `fwrite` debug read got the correct value; the next read
drifted. `readonly`, single-local capture, and a typed-helper return all failed.

- Likely the same representation issue as BUG-1/3 (a node object's high-offset
  string field mis-loads), surfaced by the specific `MethodCall_` layout + the
  `castMethodCall($n){ return $n; }` no-op cast. Reproduce by re-adding a tail
  string field to a hot node and reading it in a pass; bisect against field
  offset / `readonly` / the cast.
- The backtrace feature side-steps it entirely (callee-side name stamp via
  `Module::$methodDisplay`), so this is not blocking — but it is a landmine for
  any future "stamp data on a node, read it in EmitLlvm" plan.

## NOT bugs (dismissed)
- **ternary-in-concat "swap"** (`$a . ($c?'::':'->') . $b` → `"$b<sep>$a"`): could
  NOT be reproduced standalone. The `$sep` hoist did NOT fix the original symptom;
  the `@var` fix did. It was a mis-attributed sighting of BUG-1.
- **debug_backtrace return type**: `vec(assoc(string,cell))` read string ptrs as
  floats. That was a correct type annotation fix (values are strings →
  `vec(assoc(string,string))`), not a compiler bug.

---

## Suggested order
1. **BUG-3** first — sharpest, standalone, and unblocks real int `line` in traces.
   Trace the assoc-literal value store path for a `substr` temp + an int in the
   same literal.
2. **BUG-1** — extend bare-`array` property VALUE inference; drop the `@var`
   workarounds afterward and re-gate.
3. **BUG-2** — only if 1–2 don't already explain it; reproduce by re-adding a tail
   field to `MethodCall_` and reading it in EmitLlvm.

## Entry points
- Array/assoc literal + element value boxing: `EmitLlvm` array-literal emit,
  `InferTypes` element/value type, `UnifiedArrayRuntime`.
- Property value inference: `LowerFromAst::buildClassDef` (the `$this->p[]=` scan,
  `0eacfb6`), `ClassDef::$propertyTypes`.
- The `@var` value-type honouring path (proof the mechanism exists).

## Verify a fix
Both repros print PHP-identical output, THEN drop the corresponding workaround
(the `@var` on `Module::$methodDisplay` for BUG-1; restore int `line` + the
`"line"=>$ln` key in `LowerFromAst::backtraceFramesSrc` for BUG-3) and re-run the
full gate: `bash tools/selfhost_fixpoint.sh` + `bash tests/aot/run.sh` +
`bash tools/difftest.sh`.
