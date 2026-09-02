# T6: what causes the explosion — erased property dispatch, one arm per class

Answered by the emission batch trace (`MANTICORE_PHASE_TRACE=1`), which reports
every 1024 functions: cumulative IR, RSS, and the fattest single body in the
batch with its name.

## The measurement

```
45056  ir=206MB rss= 8875MB d=   +3       2KB @44764 __mc_ifaces_…FilesystemTagAwareAdapter
46080  ir=206MB rss= 8878MB d=   +3       1KB @45133 __mc_ifaces_App_Audit_RedisDoctrineRoot
47104  ir=218MB rss= 8949MB d=  +71     948KB @47056 __mir_print_r_str
48128  ir=384MB rss=14639MB d=+5690   54798KB @47891 PDOStatement__makeObject
49152  ir=497MB rss=15058MB d= +419  103650KB @48693 ClosureExpressionVisitor__getObjectFieldValue
52224  ir=573MB rss=15402MB d= +219    8801KB @51970 EventManager__dispatchEvent
```

Up to batch 47104 the fattest body in a whole batch of 1024 functions is
**1–2 KB**. Then two single functions come in at **53 MB and 101 MB of IR
text**, and RSS jumps **+5.7 GB in one 1024-function window** against +166 MB of
IR — 34×, where the steady ratio before it is ~4×.

So the T6 peak is not accumulation. It is a handful of monstrous bodies, and
the memory is the cost of BUILDING each one (string append, `HoistAllocas`,
canonicalisation) rather than of keeping them: they are streamed to disk.

## The mechanism, from the emitter's own comment

`EmitLlvmObjects::canonArm`:

> A property dispatch emits one arm per CLASS, but the arm is a function of the
> SLOT (offset, width, signedness, declared type, array-hint), not of the class

and at the three dispatch sites (`EmitLlvmObjects.php:964`, `:1257`, `:1427`):

> the receiver is ERASED, so "one class declares `__get`" says nothing about the
> class in hand. Always dispatch on class_id.

That is correct and it is why the sites exist. The size consequence is the
problem: **T6 has 3 130 classes**, so one erased property access emits a
class_id switch with up to 3 130 arms.

The two named functions are exactly that shape:
- `ClosureExpressionVisitor::getObjectFieldValue` — reads `$object->$field` off
  an arbitrary object. Fully erased receiver, every class a candidate.
- `PDOStatement::makeObject` — populates properties of a dynamically named
  class.

`canonArm` is the existing mitigation and it is working as designed — arms that
share a slot layout collapse to one block. It cannot collapse 3 130
heterogeneous classes whose offsets, widths and declared types genuinely differ.

`sample` at the peak agrees: `EmitLlvm::canonArm` is the second frame, behind
`__mir_array_index_find` (the dedup table lookup).

## Why this is a different root from the element-drop fix

That fix repays references the FRONT strands; the front is 8 114 MB of a 25 GB
run and is flat. This is a codegen SIZE problem in emission. Both stand; neither
explains the other.

## Where to attack it

1. **Bound the arm count.** A switch with thousands of arms is not the only
   sound lowering — a runtime helper that looks the slot up in a per-class
   table, taken when the candidate set exceeds some threshold, is O(1) IR
   instead of O(classes).
2. **Narrow the candidate set.** These sites take EVERY class because the
   receiver is erased. Anything that proves a smaller set (a declared param
   type, an interface bound) cuts the arms directly.
3. Only then measure again: the run still caps at 20 GiB with 21 000 functions
   left to emit, so there may be more than these two sites.

## Method note

Two runs were lost to instrumentation error before this table existed — one to
a harness ceiling that judged `ps rss` (which collapses under memory pressure)
and one to placing a `substr` inside the sink-marker window, which rewrote a
borrowed path and failed the build at function 46088. Both are fixed and both
are recorded where the next person will hit them.
