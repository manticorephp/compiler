# Cycle Collector — Design

Refcount alone leaks cyclic object graphs (`$a->ref = $b; $b->ref = $a`).
This doc plans a Bacon-Rajan trial-deletion cycle collector that sits on top
of the existing refcount layer.

## Position in the pipeline

- **Refcount** handles the common case: every non-cycle object dies at
  `rc == 0`. Already landed.
- **Cycle collector** handles only the corner case where mutual references
  prevent `rc == 0`. Runs rarely, triggered manually or on a threshold.
- **No barriers, no write trapping** — the algorithm is reachability-based
  at collection time, not at mutation time.

## Algorithm summary (Bacon-Rajan 2001)

Each object carries a "color" and a "buffered" flag.

| Color | Meaning |
|-------|---------|
| black | In use or freed; reachable from a strong root |
| gray | Possible member of a cycle, being scanned |
| white | Confirmed garbage, ready to free |
| purple | Candidate root (rc decremented but > 0) |

Outline:

```
Decrement(s):                       # called from obj_release
    s.rc -= 1
    if s.rc == 0:
        Release(s)
    else:
        PossibleRoot(s)             # might be a cycle root

PossibleRoot(s):
    if s.color != purple:
        s.color = purple
        if !s.buffered:
            s.buffered = true
            roots.push(s)

CollectCycles():                    # entry point
    MarkRoots()
    ScanRoots()
    CollectRoots()

MarkRoots():
    for s in roots:
        if s.color == purple:
            MarkGray(s)
        else:
            s.buffered = false
            roots.remove(s)
            if s.color == black && s.rc == 0:
                free(s)

MarkGray(s):
    if s.color != gray:
        s.color = gray
        for t in children(s):
            t.rc -= 1               # trial deletion
            MarkGray(t)

ScanRoots():
    for s in roots: Scan(s)

Scan(s):
    if s.color != gray: return
    if s.rc > 0:
        ScanBlack(s)                # external ref keeps it live
    else:
        s.color = white
        for t in children(s): Scan(t)

ScanBlack(s):                       # restore the trial-deletion side-effect
    s.color = black
    for t in children(s):
        t.rc += 1
        if t.color != black: ScanBlack(t)

CollectRoots():
    for s in roots:
        s.buffered = false
        CollectWhite(s)
    roots.clear()

CollectWhite(s):
    if s.color == white && !s.buffered:
        s.color = black
        for t in children(s): CollectWhite(t)
        Free(s)                     # destructor + libc free
```

The trial-deletion idea: walk descendants of every root, decrementing each
child's rc (simulating their owner going away). If a child still has rc > 0
afterwards, an external pointer keeps it live → not garbage. Otherwise it
participates in an isolated cycle → collect.

## Manticore-specific implementation

### Header layout

Today: 16-byte object header.

```
offset 0  : class_id    (i64)
offset 8  : rc          (i64)
offset 16 : fields...
```

Cycle collector needs 2 more flags. Two options:

**Option A — pack into rc upper bits.**
RC is i64; cap effective rc at 2^48, use 16 bits for color+buffered+pad.
Pros: zero header growth, all property offsets unchanged.
Cons: every rc bump/dec needs a mask. Higher rc cap good for production.

**Option B — extend header to 24 bytes.**
Add `i64 color_state` at offset 16 (color in low byte, buffered in next byte,
padding the rest). Properties shift to offset 24.
Pros: simple read/write of state.
Cons: shifts every property offset by 8; every property GEP in IR needs
re-emit. Risky given the self-host build sensitivity to layout changes.

**Pick Option A**. The masking cost is one extra `and` per retain / release;
the saved layout churn far outweighs that.

### Encoding (Option A)

`rc_word` is the i64 at offset 8.

```
bit 63       : buffered flag
bits 62-56   : color (only need 2 bits today, reserve 7 for future)
bits 55-0    : rc value (max 2^56 ≈ 7.2 × 10^16)
```

Helpers:

```
RC_MASK    = 0x00FFFFFFFFFFFFFF
COLOR_MASK = 0x7F00000000000000
BUFFERED   = 0x8000000000000000

rc_load    : load i64, then `and RC_MASK`
rc_store   : load color/buffered bits, mask in new rc
color_load : load i64, then `shr 56`, then `and 0x7F`
color_store: load i64, mask off color, or in (color << 56)
```

### Per-class child enumeration

The collector needs to walk an object's owned children (ptr-typed properties
that hold refcounted objects).

Already implemented as part of the destructor switch in
`emitObjReleaseHelper`: each non-Struct class with at least one
ptr-refcounted property contributes a cleanup block that does
`obj_release` on each child.

For the collector we need the SAME enumeration but doing _different_
actions per child (decrement, scan, restore, collect). Refactor:

```
emitClassChildrenLoop(class, fn) :=
    for each refcounted ptr prop p of class:
        load child = obj->p
        if child != null:
            fn(child)
```

`fn` is the per-action callback (one for MarkGray, one for Scan, one for
ScanBlack, one for CollectWhite). The class-switch lives in a single
runtime function `__manticore_obj_for_each_child(obj, action_id)` that
dispatches on `class_id` and `action_id`. Implementation detail: the
action_id can index into a function-pointer table or a switch on action_id
inside each case.

### Trigger policy

V1: manual only. PHP `gc_collect_cycles()` becomes a builtin that calls
`__manticore_cc_collect_cycles()`.

V2: threshold trigger. `__manticore_cc_candidates` carries a count; on
push-past-threshold, `__manticore_cc_collect_cycles()` runs.

### Memory for the candidate buffer

A dynamically-grown libc-alloc'd vec of `ptr`. Capacity doubles on overflow.
Buffer never frees during process lifetime (single growable allocator).

### Integration points

- `__manticore_obj_release`: after the `rc -= 1`, if `rc > 0` AND the
  object hasn't already been marked purple, call `__manticore_cc_add_root`.
  Skip for Struct classes and `#[NoRefcount]` classes (their cap stays at
  0 from refcount layer).
- `__manticore_cc_add_root(obj)`: stamp color = purple, set buffered, push
  to candidate list. Fast inline path — no allocation if already buffered.
- `__manticore_cc_collect_cycles()`: runs the 4-phase algorithm above.

## Phasing

1. **Phase 1**: header bit-packing. Update `obj_retain` / `obj_release`
   helpers to read/write only the rc bits via mask. Existing alias-mark
   stays where it is. Verify 170/170 green.
2. **Phase 2**: candidate buffer + `cc_add_root`. Wire from `obj_release`.
   No collection yet — just accumulate. Verify 170/170 green.
3. **Phase 3**: per-class child walker. Refactor destructor-case emission
   to share a single child-enum helper. Verify 170/170 green.
4. **Phase 4**: trial-deletion algorithm (Mark / Scan / Collect). Expose
   as `gc_collect_cycles()` PHP builtin. Test with deliberately-cyclic
   PHP fixtures.
5. **Phase 5**: threshold-based auto-trigger. Tunable via environment
   variable `MANTICORE_CC_THRESHOLD`.

Each phase rebuilds bin/manticore and runs tests/aot/run.sh between
landings — same discipline that flushed out the refcount layer.

## Test plan

New test cases under `tests/aot/cases/`:

- `gc_simple_cycle.php` — two-object cycle, manual `gc_collect_cycles()`.
- `gc_three_cycle.php` — three-node cycle via `$a->next = $b; $b->next = $c;
  $c->next = $a`.
- `gc_no_collect_when_live.php` — cycle with an external root, must not
  collect.
- `gc_self_cycle.php` — `$a->self = $a`.
- `gc_idempotent.php` — calling `gc_collect_cycles()` twice in a row is a
  no-op the second time.

Validation harness: instrument `__manticore_cc_collect_cycles` to print
candidate count + freed count when `MANTICORE_DEBUG_CC_TRACE=1`. Use that
to assert expected freed counts in the test bodies (string compare).

## Risks

1. **Re-entrancy via destructors**. A freed object's destructor may run
   user PHP that touches more objects. Until destructors are fully
   re-entrant-safe, collection happens with a global "in-cc" flag that
   suppresses recursive `cc_add_root` calls.
2. **Bit-pack regression**. Changing rc storage to bit-packed touches a
   hot path. Phase 1 lands alone with extra tests to verify retain/release
   correctness before any CC logic stacks on.
3. **Self-host build fragility**. Past changes to ClassInfo and the
   `exprProducesObject` predicate triggered hard-to-diagnose SIGSEGVs in
   the bootstrap (see [[obj-alias-norefcount-field-bug]]). Each cycle
   collector phase rebuilds the self-host and runs `tests/aot/run.sh`
   immediately; if a phase regresses, revert and add a memory note before
   re-attempt.
