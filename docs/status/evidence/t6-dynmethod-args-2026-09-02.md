# Dynamic method calls with arguments: extracted, and why T6 did not move

## The change (`0255cbe`)

`dynamicMethodZeroArgHelper` was gated on `$iv->args === []`. Its own doc gave
the reason — "no by-ref/spread/default argument contracts to preserve" — which
is true of a zero-arg call and merely UNCHECKED of any other. Now checked:
a spread has no fixed arity to hand a helper, and a by-ref parameter needs the
inline path's address discipline, so both are tested rather than assumed.

Everything else was already register-based. `vdArmArgs` boxes/unboxes
cell↔non-cell, pads the arity and handles the spread tail from a LIST STRING and
two type arrays — never from nodes — so arguments ride in as extra `i64`
parameters. The site's argument TYPES are part of the helper key: two sites
calling the same name with differently-typed arguments need different coercion,
and one helper serving both would be a silent wrong answer.

Stand (`$o->$m($e)`, the `__mc_call_exception_handler` shape):

| classes | before | after |
|---:|---:|---:|
| 50 | 38 788 B | **19 152 B** |
| 200 | 151 188 B | **70 820 B** |

Slope 749 → **344 B/class**. Zero-arg calls stay flat (30 754 → 30 763 B).
Suite **1029 passed, 0 failed, 1031 total**, plus `dyn_method_args_erased`
(overrides, argument order, a cell argument into a typed parameter).

## ⚠ The gate did not fire at all until the predicate was fixed

The first version vetoed every candidate. `MANTICORE_DYNM_TRACE=1` said
`DYNM veto: D1::handle by-ref` for `handle(mixed $e)`, which has no by-ref
parameter at all: **`sigs->refParams[$fn]` is a per-parameter BOOL ARRAY**, so an
ordinary one-argument method is `[false]` — non-empty — and `!== []` read that as
by-ref. The predicate already had an owner, `anyRefParam()`; re-deriving it
instead of asking is the same mistake as re-deriving the bag retain discipline
earlier in this branch. A conservative gate fails SILENTLY, which is why the
trace was added and kept.

## ⚠⚠ T6 did NOT move, and the reason is a THIRD instance of one pattern

```
before  costliest=1876MB @47386 __mc_call_exception_handler   ir=313MB
after   costliest=1913MB @47386 __mc_call_exception_handler   ir=313MB
```

The veto is SITE-WIDE: one candidate anywhere in the program with a by-ref
parameter disables the extraction for the whole site. Reproduced in 30 seconds
by adding a single such class to the stand:

```
class Poison { public function taint(array &$r): int { … } }
DYNM veto: Poison::taint by-ref
call1 = 152 081 B      (was 70 820 B without it)
```

T6 has 3 130 Symfony/Doctrine classes, so such a method certainly exists and the
prelude site never extracts. This is the same shape as `$hasBag` killing the
property-reader extraction, and as the bare-name borrow veto costing 63% of the
drops: **a module-wide veto on a per-name decision**.

## The fix, specified

Make the veto PER METHOD NAME. The arms are already per name, so a clean name
calls the shared helper while a dirty one keeps the inline emission:

1. split `$methods` into clean / dirty by `anyRefParam` over that NAME's
   candidates;
2. emit the clean chain (strcmp → call helper → store `$res` → br END);
3. leave `$methods = $dirty` for the general path below, which then emits only
   those arms;
4. store the general path's result into the same `$res` and merge at END.

Steps 3-4 are the invasive part: the extract block currently `return`s, and the
general path allocates its own result and has a separate trailing-spread branch.
That restructure is the next work item and was NOT attempted here — this branch
has already shown twice what a hurried guess in this emitter costs.
