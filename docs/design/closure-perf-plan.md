# Closure performance — implementation plan (next session)

Status: **IMPLEMENTED — 2026-06-28, HEAD `84c4862`.** Both steps shipped as the
post-InferTypes `InlineClosures` pass (`src/Compile/Mir/Passes/InlineClosures.php`),
wired in `Main.php::lower_module` after InferTypes / before Monomorphize, with a
re-InferTypes after. Gates green: suite 336/336, difftest 327/0, fixpoint
byte-identical, self-host 336/336, stability 5×2.

**Key deviation from the plan below:** step #2 fusion was moved from a LOWERING
desugar to the post-InferTypes pass and GATED on a concretely-typed array
(`isConcreteArray`), with the synthesized loop fn typed to that array directly.
The lowering-time / Monomorphize-specialization route (as originally sketched)
miscompiled: erased-array params + the ≥2-shapes specialization threshold left
single-use synth fns unspecialized → foreach-over-unknown garbled assoc keys and
chained `map(g, filter(f,$a))` did native arithmetic on unknown operands →
garbage values. Typing the synth fn from the call-site array eliminates the
Monomorphize dependency and the unspecialized miscompile hole; a non-concrete
array (chained outer call / dynamic source) is left as the prelude call. Filter
int-key renumbering is a pre-existing dense-vec limitation (the prelude
array_filter renumbers identically), not a fusion regression.

---

(Original plan, kept for reference.)

## The problem (measured, M1 Pro, -O2, median, vs PHP 8.5.3)

| case | manticore | speedup | note |
|---|---|---|---|
| inline `$x*$x` in a loop | 0.005 s | **20×** | baseline: no closure |
| `$f=fn($x)=>$x*$x; $f($i)` in a loop | 0.073 s | **3.5×** | direct closure invoke |
| `array_map`/`filter`/`reduce` (funcarr) | 0.050 s | **2.1×** | the common real case |
| funcarr hand-inlined (fused loop) | 0.006 s | **11×** | the ceiling |

foreach itself is NOT the bottleneck (vec 16.6×, assoc 24.3× — LLVM hoists the
loop-invariant flags load). compute is 8–28×. The ONE remaining iteration tax is
the **closure call ABI**.

## Root cause (verified from emitted IR)

A closure call lowers to **`KIND_INVOKE`** (NOT `KIND_CALL`, so `Monomorphize`'s
`collectCalls` never sees it). `emitInvoke`
(`src/Compile/Mir/Passes/EmitLlvm.php:3617`) hardcodes the **uniform boxed
closure ABI** (comment at line 3635 "Uniform closure ABI: box each scalar arg"):

```
%boxed = call @__manticore_box_int(i64 %arg)        ; box the arg
%r     = call @manticore___closure_0(ptr %env, i64 %boxed)
                                                     ; body does @__manticore_tagged_mul (cell arith)
%sum   = call @__manticore_tagged_add(...)          ; cell return -> tagged add at caller
```

Three cell ops/iteration. The body uses `tagged_mul` because the closure param
`$x` is typed CELL/unknown (untyped param). Plus `array_map`/`array_filter` build
**intermediate arrays** (fusion would remove those too).

Closure FunctionDef shape: params = `[capture1..captureN, userparam1..]`; the env
struct ptr is a synthetic param 0 added in emit (captures unpacked from it).
`closureCaptures[name]` = capture count. `inferInvoke`
(`InferTypes.php:1776`) types the invoke result from `sigs[$calleeClass]` (the
closure's return type) — so if a specialized/typed closure returns int, the
invoke result types int automatically.

## Chosen approach: INLINE the closure body (avoid the ABI entirely)

Do NOT try to monomorphize the closure as a separate typed function — the env
packing + uniform-ABI coordination is the fragile, previously-reverted path
("do all three together, gate hard", see memory `session_handoff_2026_06_19_NEXT`).
Instead **eliminate the closure** for the inlinable cases by splicing its body:

A captureless single-expression arrow closure `fn($x) => <expr>` invoked with a
known callee becomes `<expr>` with `$x` substituted by the arg. No call, no box,
no env, native ops → the 11–20× ceiling.

### Shared primitive: `spliceClosureBody(closureFn, argNodes): ?Node`
- Eligible closure: `closureCaptures[name] === 0` (captureless) AND body is a
  `Block` with exactly one `Return_($expr)` ($expr non-null) AND param count ==
  arg count. Else return null (caller falls back to the normal invoke — SAFE).
- Splice: `NodeClone::node($expr)`, then walk the clone replacing every
  `LoadLocal(paramName)` with a clone of the matching arg node.
- Arg safety: only inline when every arg is side-effect-free + cheap to
  duplicate (`KIND_LOAD_LOCAL`, the const kinds, `KIND_PROPERTY_ACCESS`). For
  anything else (a call, etc.) return null (skip). Avoids needing a temp/hoist,
  since the expr is embedded in a larger expression and MIR has no
  statement-before-expression slot.

### Step #1 — direct invoke inline (the warm-up, validates the splice)
New MIR pass `InlineClosures` run AFTER `InferTypes`
(`Main.php:1300`, and the second pipeline at `:1400`) and BEFORE `Monomorphize`,
then re-run `InferTypes` (the spliced expr needs typing).

- NO generic rewrite-Walk exists (`Walk` only has read-only `children`), so the
  pass needs a `ConstFold`-style `rewriteNode(Node): Node` that recurses every
  node kind and rebuilds children in place (model on
  `ConstFold::foldNode`, `ConstFold.php`). At `KIND_INVOKE`: if callee
  `->type->class` is a known closure and `spliceClosureBody` succeeds, return the
  spliced node; else recurse args/callee normally.
- Build `closuresByName` (name → FunctionDef) once from `module->functions`.
- Test `clos.php` (`$f=fn($x)=>$x*$x; loop $f($i)`): expect 3.5× → ~20×.

ALTERNATIVE to a new full-traversal pass: fold the inline into
`ConstFold::foldInvoke` (`ConstFold.php:375`, already called for every invoke) —
BUT ConstFold runs before InferTypes (`Main.php:1296`), so the callee type
(`obj<__closure_N>`) isn't known there. Either (a) track `$f = Closure_` straight-
line in ConstFold like `LowerFromAst::$constCallables`, or (b) prefer the new
post-InferTypes pass (cleaner, types known). Recommend (b).

### Step #2 — array_map / array_filter / array_reduce fusion (the real win)
These live in `prelude/array_fns.php` and are injected + monomorphized per element
type. The slowness is the in-loop dynamic `$cb($v)` + the intermediate arrays.
Desugar at the CALL SITE when the callback is a literal closure:

- `array_map(fn($x)=>E, $a)`  → fresh `$out=[]; foreach($a as $v){ $out[]= E[$x:=$v]; } $out`
- `array_filter($a, fn($x)=>P)` → `$out=[]; foreach($a as $v){ if (E[$x:=$v]) $out[]=$v; } $out`
- `array_reduce($a, fn($c,$x)=>E, $init)` → `$acc=$init; foreach($a as $v){ $acc = E[$c:=$acc,$x:=$v]; } $acc`
- Chained `reduce(filter(map(...)))` then fuses naturally into nested/sequential
  loops; full fusion into ONE loop is a bonus, not required for the first win.

Where: simplest as a LowerFromAst desugar in `lowerCall` when the function is
`array_map`/`array_filter`/`array_reduce` and the matching arg is a `Closure_`
AST literal — build the loop AST and splice the closure body (reuse the AST-level
analogue of `spliceClosureBody`). This ELIMINATES the closure for these cases, so
it never touches the closure ABI. Captureless single-expr arrow only (v1); else
fall back to the prelude function.

Test `funcarr.php`: expect 2.1× → ~11×.

## Gotchas / gating
- **Generators are closures** — a generator closure body is NOT a single-return
  arrow; the `closureCaptures===0 && single Return_` gate excludes them. Verify a
  generator test (`tests/aot` has them) still passes.
- **Captures** — v1 only handles captureless (`use`/auto-capture → skip). A
  captured var would need binding; defer.
- **Side-effect args** — only inline simple args (skip otherwise) to avoid
  double-eval / hoist.
- **By-ref / spread args** — skip.
- Full gates each step: `tests/aot/run.sh` (expect 335+/335+), `tools/difftest.sh`
  (0-diff), `tools/selfhost_fixpoint.sh` (fixpoint byte-identical + stability 5×2).
- Measure cycle (avoid full gates per iteration): edit → `bin/build --seed`
  (~40 s) → compile `clos.php`/`funcarr.php` → time vs php. Full gates only before
  commit. (Do NOT measure via `tools/compile_files_mir.php` — it skips prelude
  injection; use `bin/manticore compile` after a reseed.)

## Benches (in /private/tmp scratchpad this session; recreate)
`clos.php`, `inl.php`, `funcarr.php`, `funcarr_inl.php` — see the measured table
above for expected php/manticore timings.
