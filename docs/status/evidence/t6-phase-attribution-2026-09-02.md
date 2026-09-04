# T6: the cliff is EMISSION, not the front — and the harness ceiling never applied

## ⚠⚠ First: `tier.sh` was capping on the wrong number

The loop compared `ps -o rss=` against `CAP_BYTES`. Under memory pressure macOS
COMPRESSES pages, so `rss` FALLS while the real footprint keeps climbing. One
tick of the cap-20 run, verbatim:

```
rss_kb          2 924 000 KB  =  2.9 GB     ← what the ceiling judged
physical_bytes  28 346 784 153 = 26.4 GB    ← what the process actually held
```

The run sailed past a 20 GiB ceiling to a **peak physical footprint of 40.8 GB
on a 32 GB machine** and had to be stopped by hand. This is the same class of
failure as the `/usr/bin/time`-wrapper era — *the configured ceiling silently
never applied* — with a different cause. Fixed: the cap now judges
`max(rss, physical footprint)`, which the loop was already sampling.

Any earlier capped reading is only trustworthy where rss still tracked the
footprint, i.e. well below the pressure point.

## The phase timeline answers the question the sampler could not

`MANTICORE_PHASE_TRACE=1` (new — the timeline without the rest of `Stats`, which
cannot be enabled on a large target):

```
stats: 724377ms +243290ms  NarrowReturns (full)  fns=73289          rss=7714MB
stats: 724439ms +62ms      ReflectAnalysis cls=3130                 rss=7714MB
stats: 725525ms +611ms     InferAllocKind                           rss=7951MB
stats: 725933ms +205ms     InsertMemoryOps                          rss=7959MB
stats: 726181ms +248ms     Verify  fns=73289 cls=3130               rss=7970MB
                           ← no further phase line: EmitLlvm never returned
```

**The ENTIRE front — parse, inference, monomorphisation, NarrowReturns, memory
ops — finishes at 7 970 MB.** Everything above that, 8 GB → 40.8 GB, is
`EmitLlvm`.

### This explains the cap-12 curve exactly

That run read linear at ~1 GB per 118 s up to **8 GB at 768 s**, then 8 → 12 GB
in 57 s. The "cliff" is not a pathology inside one pass: **8 GB and ~730 s is
where the front ENDS and emission BEGINS.** The two runs agree to within the
sampling interval.

So the standing note — "the real target is the LAST 84 s that adds 4.8 GB" —
is right about the location and far understates the size: with a ceiling that
actually holds, emission adds **more than 32 GB**, four times the front's whole
peak.

## Where emission spends it

`sample` at 40.8 GB (`t6-gen3-cap20-sample-40g.txt`), top frames:

```
305  __mir_array_index_find          ← dominant, by 3x
 99  EmitLlvm::canonArm
 98  __mir_str_append
 80  __mir_rc_release_str
 79  __mir_array_implode
 75  SsaBuilder::allocReg
 70  __mir_array_get_str
 61  EmitLlvm::emitCellPropertyRead
```

⚠ That is a CPU profile, not an allocation profile — it names where the time
goes, and `__mir_array_index_find` dominating means linear scans in array
lookup. It is a lead for the next step, not a proof of what holds the memory.

## Where this leaves the element-drop fix

Unaffected and unchallenged: that fix repays references the FRONT strands, and
the front is 8 GB of a 40 GB run. It was never going to move this. The two
findings are consistent; neither argues against the other.

## Next

1. Re-run T6 with the FIXED ceiling (`CAP_GB=20`) to get an honest capped
   diagnostic, now that the cap can actually fire.
2. Attribute emission's memory with allocation counters rather than a CPU
   sample — the front is instrumented per phase, emission is one opaque line.
   `EmitLlvm` needs sub-phase timeline entries of its own.
