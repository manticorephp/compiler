# rcbalance Phase 1 — which ownership shape leaks

Compiler: cold-seed bootstrap of `t6mem` at `dc9e11f` (`bin/build --seed`, 5 m 34 s,
ABI 8 binary + ABI 8 `lib/manticore_stdlib.o` — the first coherent pair in the repo
since 2026-08-24). Fixture compiled with `MANTICORE_PROFILE=1 MANTICORE_POOL=0`.

Verdict per variant is `tagged_alloc − tagged_reclaim`, i.e. objects still live at
exit. It must be a small constant independent of the iteration count; a value that
tracks the count is one leaked reference per iteration.

| variant | live @50k | live @200k | verdict |
|---|---:|---:|---|
| `ret_stmt` | 0 | 0 | flat — control |
| `ret_arg_obj` | 0 | 0 | flat — control |
| `ret_arg_vec` | 0 | 0 | flat — control |
| `ret_arg_assoc` | 0 | 0 | **flat — hypothesis refuted** |
| `ret_recv` | 50 000 | 200 000 | **LEAK, 1/iteration** |
| `ret_cond` | 50 000 | 200 000 | **LEAK, 1/iteration** |
| `ret_foreach` | 0 | 0 | flat |
| `prop_ow_read` | 49 999 | 199 999 | **LEAK, 1/iteration** |
| `prop_ow_noread` | 0 | 0 | flat — control |
| `append_pinned` | 0 | 0 | flat; str bytes linear 4.0× |
| `append_free` | 0 | 0 | flat; str bytes linear 4.0× |

## Confirmed

**A — a call result in RECEIVER position is never released.** `f()->m()` takes the
callee's `+1` and drops it on the floor. `ret_arg_obj` proves the argument position
is covered, so the discriminator is the position, not the type.

**B — a call result in CONDITION position is never released.** `if (f()->ok())`,
same shape.

**C — a property overwrite leaks its old value whenever the slot is read anywhere
else in the program.** `prop_ow_read` and `prop_ow_noread` store into the same kind
of slot in the same loop; the only difference is that `SlotRead` also has a
`peek()` getter. One leaks every overwritten value, the other is exactly flat. This
is `markPropBorrow`'s default arm vetoing the release-before-overwrite, and the veto
is keyed per DECLARING CLASS — which is why the two shapes must live in two
different classes or they contaminate each other.

C is the one that scales: every getter-plus-assignment class in Doctrine, in
Symfony, and in `src/` itself matches it. It is consistent with `retain_prop =
101,158,509` and `tagged_reclaim = 145,707` on the g139 Doctrine dump.

## Refuted, and worth recording

**The assoc arg-temp hole did not reproduce.** `EmitLlvm.php:2698` returns `''` for
`isAssoc()` under a comment that contradicts `EmitLlvmModule.php:1974`, so the code
reads like a leak — and `ret_arg_assoc` is flat. The stale comment is real; the leak
it predicts is not, at least in this shape. Do not "fix" it on the reading alone.

**The string-append amplifier did not reproduce here.** `append_pinned` and
`append_free` are byte-for-byte identical and linear, so storing the accumulator
into a property did not pin its refcount above 1. The quadratic shape seen on
Doctrine needs a different pin; this variant does not construct one.

## Reproduce

```bash
env MANTICORE_PROFILE=1 MANTICORE_POOL=0 bin/manticore compile \
    tools/prof/rcbalance.php -o /tmp/rcbalance
/tmp/rcbalance <variant> <iters>
```

⚠ `global $argv` inside a function binds nothing in the compiled binary — the first
run of this table read FLAT for every row because every variant silently ran as the
default one. argv is read at top level and passed in.
