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

---

# Per-name veto (`96a4e0f`): the 1 876 MB function is gone

The seam was already there: `emitDynMethodInlineFallback($dp, $iv, $methods)`
takes the METHOD SET, so the split needed no restructure of the emitter.

- names split clean / dirty by `anyRefParam` **per name** (memoised — otherwise
  201 names x 3 130 classes at every site);
- the clean chain calls the shared helper;
- if no clean name matched, control falls into that same inline dispatcher over
  the DIRTY SUBSET ONLY;
- both halves merge into one `$res`.

⚠ One condition had to come with it: the inline fallback RE-EMITS the receiver,
the name and the arguments. Only one branch runs at runtime, but the operands are
emitted twice, so each must be side-effect free to be evaluated twice — hence
`LOAD_LOCAL`/const is required of the name and every argument, for the same
reason the receiver already had to be a local. Anything else keeps the whole site
inline, as before.

Poison stand (one `taint(array &$r)` class among 200):

| | before | after |
|---|---:|---:|
| `call1`, no by-ref class | 70 820 B | 70 820 B |
| `call1`, with one | **152 081 B** | **71 930 B** |

A by-ref method now costs its own arm (1 110 B), not the site's extraction.

Suite **1030 passed, 0 failed, 1032 total**, plus
`dyn_method_byref_neighbour` (a by-ref method beside ordinary ones; both halves
must work, and the by-ref array really is mutated).

## T6, at equal work

```
batch    before (dynm)          after (per-name)
47104    ir=201MB rss= 8960MB   ir=201MB rss=8845MB
48128    ir=313MB rss=14364MB   ir=275MB rss=8845MB
50176    ir=353MB rss=14462MB   ir=315MB rss=8845MB
52224    ir=411MB rss=14485MB   ir=366MB rss=8898MB
```

**The +5.5 GB step is gone** — RSS is flat across four batches where it used to
jump. −5.6 GB at batch 52224. `__mc_call_exception_handler` no longer appears in
the costliest list at all; the leaders are now `__mc_unser_set` (78 MB) and
`EventManager::dispatchEvent` (29 MB), two orders of magnitude smaller.

## ⚠⚠ But the run still caps, and the two memory metrics disagree by 2x

At the last batch the compiler's own `memory_get_usage()` peak reads
**9 265 MB**, while the harness recorded **20.79 GB of physical footprint** (and
`ps rss` 11.0 GB) for the same process.

`ru_maxrss` is a RESIDENT peak; the physical footprint includes compressed pages.
A 2x gap therefore means roughly half the process's memory is allocated, written
once and never touched again — cold RETAINED memory, not working set. That points
at retention (caches, stranded structures), not at the size of the computation,
and it is invisible to any `ps rss` reading — which is exactly why the cap had to
judge the footprint.

**Peak comparisons across the session remain near-meaningless**: every run still
hits the ceiling (23.19 / 21.69 / 20.89 / 21.19 / 21.19 / 20.79 GB), so those
numbers describe the cap, not the demand. The equal-work RSS column is the honest
measurement, and it improved by 5.6 GB.

**Next**: chase the cold half. A run under a cap high enough to FINISH would give
a true peak for the first time; failing that, the retention has to be attributed
with allocation counters rather than RSS.
