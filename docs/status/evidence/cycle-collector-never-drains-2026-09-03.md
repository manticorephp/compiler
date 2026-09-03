# The root: the cycle-collector root buffer has no drain

## What the rc trace showed

A new per-buffer / per-object rc trace (`MANTICORE_ARR_RC_TRACE=1`, both halves)
answered the question that three rounds of predicate-reading could not.

The token BUFFERS are fine. Traced inside the compiler, lexing a 3-line file:

```
ret __mir_array_retain_obj        arr=0x…538 len=25 rc=2
rel __mir_array_release_ownel_obj arr=0x…538 len=25 rc=1
rel __mir_array_release_ownel_obj arr=0x…538 len=25 rc=0     ← reaches zero
```

Both len=25 buffers reach rc 0 and are released with an element-walking flavor.
So the elements ARE decremented. The object half then showed why they still do
not die:

```
rc=-9151314442816847872   ← 0x8100000000000000
rc=-9151314442816847871   ← 0x8100000000000001
```

The rc word is not a count. **The live count is the low 56 bits; the top byte is
the collector's colour and buffered bit.** Decoded: 31 objects reach a live count
of ZERO — against `tagged_reclaim = 5`.

## Why a count of zero does not free

`__mir_rc_release` decodes the count correctly (`shl 8` / `ashr 8`), reaches the
`free:` block, and then:

```llvm
; A *buffered* object is NOT freed here even at rc<=0 —
; the collector owns it, else its candidate list dangles.
%bufb = and i64 %rc1, BUFFERED_MASK      ; = PHP_INT_MIN, the top bit
br i1 %isbuf, label %done, label %dofree
```

`0x81…` has the top bit set, so these objects are BUFFERED, coloured PURPLE, and
handed to the collector.

**And the collector never runs.** `__manticore_cc_collect_cycles` has exactly one
caller: the `gc_collect_cycles()` builtin. Nothing in the compiler calls it.
`__manticore_cc_add_root` pushes onto a list that only ever GROWS (`grow` doubles
the capacity); there is no threshold, no drain, nothing.

So every object whose refcount is ever decremented to a non-zero value is
buffered, and when it later reaches zero it is not freed — it waits for a
collection that never comes.

That is the whole picture, and it fits every measurement:

| | observed | explanation |
|---|---|---|
| objects freed | 0.27% | only those that die on their FIRST decrement, never buffered |
| arrays reclaimed | 96% | arrays are not cycle candidates — they are never buffered |
| `Lexer\Token` freed | 0 of 168 705 | every token is retained more than once, so every one is buffered |

## What landed, and what it exposed

`MANTICORE_AUTO_GC=<threshold>` (default OFF) drains the buffer at a threshold,
the way php does at 10 000 roots, with a re-entrancy guard — collection frees
objects, which releases their children, which re-enters `cc_add_root`.

⚠ **Turning it on crashes.** Threshold 8 on the 3-line file: SIGSEGV. That is
consistent rather than surprising — the collector has never run in this codebase,
because its only trigger is a builtin nothing calls. It is untested code.

Suite with the flag off: **1031 passed, 0 failed, 1033 total** — the emitted code
is unchanged when the flag is off.

## Next

The collector itself needs finishing before the drain can be turned on. The order
is now clear and each step is checkable:

1. Make `__manticore_cc_collect_cycles` survive being called mid-run (the crash
   at threshold 8 on a 3-line file is the smallest possible reproducer).
2. Then raise the threshold to php's 10 000 and re-measure the census — the
   prediction is that object reclaim moves off 0.27% for the first time.
3. Only then re-measure T6. Until the collector works, no amount of
   retain/release correctness can free a buffered object.

⚠ Three earlier attempts to explain this by reading emitter predicates produced
two no-ops and one masked fix. The rc trace answered it in one run. Build the
observation before arguing about the mechanism.
