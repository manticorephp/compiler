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

---

# Finishing the collector: the safe point, and what it exposed next

## The trigger belongs at an ALLOCATION, not at the buffering site

Draining from `cc_add_root` means running a full mark/scan from inside
`__mir_rc_release` — the inliner even flattens the two — on a graph that is
mid-release. It crashed at once:

```
frame #0  __manticore_cc_children + 4      ← ldr x8,[x8] on a NULL descriptor
frame #1  __manticore_cc_collect_cycles
frame #2  __mir_rc_release
frame #3  __mir_array_release_ownel_cell
```

Moved to `__mir_alloc_tagged`, which is the safe point php uses for the same
reason: nothing is half-released and the object being allocated is not yet
reachable. The `active` guard stays — collection releases children, and a
release allocates nothing but must not re-enter a running collection.

That crash is gone.

## ⚠ What the collector does next: a double free

With the drain at the allocation and a threshold of 8 on a 3-line file:

```
stop reason = EXC_BREAKPOINT   libsystem_malloc mfm_free.cold
frame #1  mfm_free
frame #2  __mir_rc_release + 88
frame #3  __mir_array_release_ownel_obj
frame #4  __mir_drop_958367453006147
frame #5  __mir_rc_release
```

libmalloc's own abort: the block is freed twice. The collector frees a white
object and ordinary rc then releases the same pointer — the surviving references
were never accounted for.

This is the expected shape for code that has never run: `cc_collect_cycles` had
exactly one caller (`gc_collect_cycles()`), which nothing invokes, so its
mark / scan / collect-white phases have never been exercised against a real
object graph.

## State

- `MANTICORE_AUTO_GC=<threshold>` is default OFF; suite **1031 passed, 0 failed,
  1033 total** with it off, and the emitted code is unchanged when off.
- Two failure modes are now characterised and separated: the re-entrancy one is
  FIXED (safe point), the double free is the collector's own.
- Smallest reproducer for what remains: `MANTICORE_AUTO_GC=8` on a 3-line file
  through `dump-ast`. Seconds per iteration.

## The order from here

1. Make the trial-deletion phases balance: an object freed by `collect_white`
   must be removed from every surviving reference's accounting, and the buffered
   bit must be cleared exactly once. The malloc abort names the double free
   precisely, so this is now a debuggable loop rather than a search.
2. Then threshold 10 000 and re-census — the prediction remains that object
   reclaim leaves 0.27% for the first time.
3. Then T6.
