# Manticore compiler lifetime follow-up — 2026-08-27

## Scope

This note records the bounded self-host, Cache/Redis, ABI8 dynamic-method, and Doctrine memory experiments performed after the g64 safe dynamic fallback extraction. It deliberately distinguishes successful measurements from experiments that were stopped or abandoned.

## Changes retained in source

The source tree retains the crash-free Cache reachability/runtime fixes, staged LLVM body emission for manifest builds, chunked `canonArm()`/cached `mangle()` construction, bounded string freelists, Darwin allocator pressure relief, pool-off default, safe-name dynamic fallback extraction, conservative visibility/missing-metadata gates, and the compiler-side `Manticore\\Allocator::release()` phase boundaries.

The phase boundaries are inserted after Lower, ConstFold, DeadStore, InferTypes rounds, InlineClosures, Monomorphize, TypeCheck, NarrowReturns, reflection analysis, InferEffects, InferAllocKind, ApplyMemoryMode, InsertMemoryOps, and before Emit. They are fail-closed and do not change target ABI or emitted object layout.

The attempted `strAppendArena()` helper and arena self-append route were removed after bounded bootstrap testing exposed unsafe arena-mode behavior. There are no remaining `strAppendArena` or `__mir_str_append_arena` references in `src`. The explicit host-wide `ApplyMemoryMode` overlay was also not retained. The selfhost launcher is back on its stable direct dump path.

## Measurements

| Experiment | Result | Interpretation |
|---|---:|---|
| g64 Cache/Redis safe dynamic fallback | RedisAdapter pipeline: 24,872,821 B; RedisTagAwareAdapter pipeline: 24,872,846 B | Same bounded target family was reduced from g34's 36,219,623 B / 36,219,648 B, approximately 31.3% by bytes. This is not a comparison with the historical 70 MB artifact. |
| g66 phase-reclaim Doctrine | Reached approximately 99.6 GiB physical-footprint peak in the observed run | Phase `gc_collect_cycles()` calls did not reclaim the dominant allocation churn; the run was stopped. |
| g69 early Doctrine stage | 1.4 GiB physical peak at about 2 minutes; 3.5 GiB sample peak at about 4 minutes | Early behavior improved substantially relative to g66. |
| g69 late Doctrine stage | User-observed footprint reached approximately 15 GiB | The late-stage problem was not solved; the run was stopped before clang/link. |
| g69 Cache host sample | 1.0 GiB physical footprint/peak during the sampled early run | Useful evidence that the early phase path is bounded, but not proof for full Doctrine. |
| g62/g64/g68/g69 focused ABI8 suite | 11/11 compile-and-run cases passed on the validated generations | Covered dynamic calls, erased receivers, defaults, magic, by-ref/variadic shapes, `is_callable`, callable forms, and closure bind. |
| g69 Cache target smoke | `psr=0`, `cache=1:redis=1:orm=0`, T1–T6, `cache=1:redis=1:trait=1` | Cache roots remained crash-free and reachable. |

## Root-cause evidence

The `sample` captured `_platform_memmove` beneath `__mir_str_append` during the late Doctrine emission stage. The g66 sample recorded approximately 3,798 `__mir_str_append` samples in the captured stack profile, while g69 still showed `__mir_str_append` as an active copy path. This identifies string append/copy churn as a real contributor, but not yet as a complete explanation of the 15 GiB late peak.

The phase counters reported zero or near-zero cycle reclamation after the major passes. Therefore, the dominant objects are not ordinary PHP cyclic garbage that `gc_collect_cycles()` can recover. The observed allocator virtual footprint is also much larger than resident memory, indicating substantial allocator fragmentation/retained mappings in addition to live compiler data.

## Negative results and safety decisions

A direct arena string append helper was prototyped, including a current-arena-tail realloc path and COW alias regression. The small alias case returned `abc:ab`, but the broader self-host bootstrap subsequently produced malformed LLVM during the first iterations and then a Bus error when explicit arena mode was applied to the full compiler source. The helper and route were removed rather than left enabled on partial evidence.

A host/target memory separation patch was tested conceptually, but existing arena runtime completeness and bootstrap lag made it unsafe to retain without a fresh cold-seed/bootstrap proof. No Doctrine success is claimed: the g69 Doctrine run did not reach a completed final link/runtime artifact after the late 15 GiB peak.

## Next implementation direction

The next safe optimization should be a compiler-only chunk sink or builder used by `EmitLlvm` body assembly, with explicit ownership and a no-alias contract, rather than making ordinary PHP strings mutable in arena mode. It should be enabled only for compiler emission structures and should preserve normal string COW semantics everywhere else. The implementation must be bootstrapped from a valid cold seed or a fully validated intermediate binary, then checked with the 11-case ABI8 suite, Cache smoke, and a bounded Doctrine run with physical-footprint samples.

No full repository test suite was run. No stale compiler, clang, or Doctrine process remained active at the time this note was written.

## g76 chunk-assembly validation

The compiler-only chunk aggregation changes were validated in a running self-hosted compiler. The 11-case ABI8 focused suite passed 11/11 on g76, covering erased dynamic receivers, defaults, magic routing, by-reference/variadic fallback shapes, Redis-like dispatch, `is_callable`, callable forms, and closure binding. The focused suite used `--memory=rc`, `MANTICORE_POOL=0`, `MANTICORE_PHASE_RECLAIM=1`, and a matching ABI8 stdlib object/signature.

A bounded Symfony Cache roots build using g76 completed the full staged-IR, clang, and link path in 153.9 seconds. Its compiler-process maximum sampled RSS was 1,145,936 KB; the captured vmmap reported a 1.0 GiB physical footprint and 1.0 GiB peak at the available samples. The linked executable printed the expected reachability output: `psr=0`, `cache=1:redis=1:orm=0`, T1 through T6 root counts of `0/20`, `1/32`, `1/36`, `1/44`, `1/58`, `1/70`, and `cache=1:redis=1:trait=1`. The run is a successful Cache smoke, not a Doctrine result.

A direct g76-to-g77 self-host completed successfully. The generated compiler LLVM was 54 MiB on disk and had SHA-256 `e28a09f56421e60a2199a8f857879d9dc0e7fbb5144bd5cf1768241ac0351790`, matching the previously recorded g75/g76 fixpoint. The compiler child reached 1,532,720 KB maximum RSS in the watcher; vmmap samples during the self-host body/emit window were 434.3 MiB, 622.8 MiB, 809.0 MiB, and 1.0 GiB physical footprint. These samples show controlled completion rather than the earlier observed multi-gibibyte late runaway, but they do not establish a same-setup reduction versus an earlier generation because the earlier g75 RSS observation was not captured with equivalent physical-footprint sampling.

The sampled g76 stacks contained ordinary `__mir_str_append` and `_platform_memmove` frames, but no captured high-count `__mir_str_append` stack comparable to the g66 late Doctrine evidence. Therefore no broad emitter rewrite is justified from this run alone. Doctrine remains unvalidated after g76 and must not be reported as successful until its final IR, clang object, link, and executable smoke all complete.


## g78–g81 follow-up: metadata isolation and property-dispatch chunking

The first attempt to chunk all per-class dynamic/rmeta/attribute metadata builders in g78 was rejected by the bounded Cache target: the compiler exited with SIGSEGV immediately after `IR: streamed bodies`, before staged IR/clang/link. The change was isolated and removed. During that rollback, a stale `$descChunks` join was found and corrected; it was a source/bootstrap error, not a target ABI issue.

A narrower g80 change converted only `EmitLlvm::emitCellPropertyRead()`'s local output, followed by g81 conversion of its polymorphic class-id switch and enum/magic arm aggregation to chunk arrays. Both g80 and g81 self-host bootstraps completed MIR verification, clang assembly, and link. The g81 Cache roots build completed staged IR, clang, and link in approximately 153.4 seconds. It emitted the same key fat-function sizes as the prior Cache target: `RedisAdapter__pipeline` 24,872,821 bytes and `RedisTagAwareAdapter__pipeline` 24,872,846 bytes. The staged IR size was 148,141,419 bytes and streamed bodies were 139,383,924 bytes. The linked executable printed the expected output: `psr=0`, `cache=1:redis=1:orm=0`, T1–T6 counts `0/20`, `1/32`, `1/36`, `1/44`, `1/58`, `1/70`, and `cache=1:redis=1:trait=1`. The g81 watcher recorded maximum RSS of 1,146,880 KB; available vmmap samples remained around the previously observed 1.0 GiB physical footprint. No semantic regression was observed in this bounded target.

The g80 Doctrine run was deliberately capped after the native compiler reached a vmmap physical footprint of 29,900.8 MB; the latest sample showed 29.2 GiB current physical footprint and a 35.4 GiB peak. Maximum watcher RSS was 13,947,024 KB. The compiler was terminated by the safety ceiling with exit 143 before final IR staging, clang, link, or executable smoke, so this is not a Doctrine success. At the late sample, vmmap showed approximately 19.5 GiB in `MALLOC_SMALL` mappings and 9.8 GiB in `MALLOC_LARGE` mappings, with approximately 18.0 GiB and 8.3 GiB respectively marked swapped/dirty in the captured summary. The stack profile continued to contain `__mir_concat_arena`, `__mir_str_append`, `_platform_memmove`, `EmitLlvm__emitCellPropertyRead`, `EmitLlvm__emitMagicCall`, and `EmitLlvm__canonArm` frames.

The controlled g80 Doctrine comparison therefore does not demonstrate a reduction versus the earlier same-workload observation: the captured peak again reached 35.4 GiB, although the run was stopped earlier at a lower current footprint. The property-read chunking is retained because it is compiler-only, self-host validated, and semantically safe, but it is not the dominant fix for the late allocator footprint. The evidence now points to broader high-volume body/arm construction and retained compiler string allocations, especially around `canonArm`, `emitMagicCall`, and the repeated `__mir_concat_arena`/`__mir_str_append` paths. A future change should target one larger demonstrated builder with an explicit sink/flush lifetime boundary, not alter PHP COW or target runtime allocation semantics.

The g78/g80/g81 temporary runners were removed. No full repository suite was run, no active compiler/clang/Doctrine process remained, and `git diff --check` was clean at the final verification.


## g82–g104 lifetime and allocator follow-up

The g81 property-dispatch chunks were followed by narrowly scoped compiler-only changes. `emitCellPropertyRead()` now aggregates its output, polymorphic switch, and arms as chunks; `emitCmp()` uses chunked construction with a final string boundary; `emitFunction()` emits top-level block statements directly into its existing body chunks and explicitly detaches the chunk list after materialization; and the self-host launcher propagates `MANTICORE_MEMORY` when the CLI does not provide `--memory`. A rejected global `visitBlock()` chunk sink is not retained because a bounded NarrowReturns workload grew from roughly 14 seconds to roughly 925 seconds. A broad rmeta/dynamic-metadata chunk conversion is also not retained because the Cache target SIGSEGVed after `IR: streamed bodies`.

The g94 source generation completed self-host, but its periodic phase-reclaim calls reported `cycles=0` and `cache_bytes=0` from emit batch 1024 through 19456 during a capped Doctrine run. This confirms that ordinary Bacon–Rajan cycle collection is not reclaiming the dominant late-Emit retention. The full g90 per-function trace reached 19,956 completed functions and approximately 210.75 MB cumulative emitted body text at 17.8 GiB physical footprint. The final emitted IR therefore remains far too small to explain the physical footprint by itself.

The native allocation evidence is consistent with compiler-runtime retention. A scoped MallocStackLogging run at approximately 4.5 GiB recorded 23,580,444 `__mir_str_alloc` events totalling approximately 2,758 MiB, 9,419,532 `__mir_alloc_tagged` events totalling approximately 761 MiB, 6,071,761 `__mir_alloc_array_tagged` events totalling approximately 552 MiB, and 712,955 tagged realloc events totalling approximately 234 MiB. A separate `leaks` snapshot at approximately 5.4 GiB reported 43,599,308 live allocations and 3,658,998,176 leaked bytes. The raw graph was approximately 1.9 GiB and was not loaded wholesale. These observations establish real live allocation/retention pressure, not merely virtual address-space growth.

The g94→g95 self-host completed with exact LLVM-IR SHA-256 `61e1c0ab4e66d183c02b49397425c4a4b37e356a72940879539553082dbaaeea`. The matching ABI8 focused suite passed 11/11, and the bounded Cache roots target completed staged IR, clang assembly, link, and smoke with the expected `psr=0`, Redis Cache reachability, T1–T6 counts, and Redis trait result. No full repository suite was run.

A new opt-in compiler diagnostic flag, `MANTICORE_ALLOC_TRACE=1`, was then added. It reuses the existing low-overhead counter bump mechanism but adds counters for string reclaim, tagged object/vec allocation and reclaim, unified-array reclaim, arena allocation, hash-bucket reclaim, and direct cycle-collector reclaim. It does not log individual allocations, alter PHP string COW, alter target ABI8, or alter ownership operations. The first instrumentation attempt exposed and fixed an off-by-one counter index before any valid measurement: the initial new hooks addressed slot 30 in a 30-slot array and caused a SIGSEGV. The corrected 31-slot implementation was bootstrapped through the self-host chain and reached the exact g103→g104 fixpoint with SHA-256 `2f0a69e2e209582914ec08c12b8124e85542c5c58a6e46219c84d4a14c9668c0`. The final diagnostic compiler emitted 31 labels, two direct CC reclaim hooks, and no out-of-bounds slot 30 issue; slot 30 is now the valid `cc_reclaim` counter.

The corrected diagnostic compiler passed the focused 11/11 ABI8 suite and the Cache roots build/link/smoke gate. The Cache smoke remained byte-for-byte semantically correct at stdout level. Its diagnostic counters were small and coherent: 78 string allocations versus 39 string reclaims, 10 array allocations versus 2 array reclaims, 7 bucket allocations versus 1 bucket reclaim, and zero direct tagged object allocations for that target. The final self-host diagnostic run reported approximately 49.86 million string allocations versus 42.51 million reclaims, 7.38 million tagged allocations versus 31,030 direct tagged reclaims, 117.72 million array allocations versus 113.10 million array reclaims, 1.59 million bucket allocations versus 0.885 million bucket reclaims, and 375.04 million versus 307.23 million object/vec retain/release calls. These are cumulative traffic counters rather than a direct live-object count, because intentional transfers, globals, nested graphs, and allocator freelists contribute to the gaps.

A single capped Doctrine diagnostic run was started with the final diagnostic compiler, `MANTICORE_POOL=0`, `MANTICORE_MEMORY=rc`, `MANTICORE_PHASE_RECLAIM=1`, matching ABI8 stdlib, and aggregate allocator checkpoints every 2^24 counter events. The safety monitor contained a process-selection bug: it sampled the `/usr/bin/time` wrapper instead of its compiler child. Its `vmmap` records showing 880K are therefore invalid, and the configured 16 GiB physical ceiling did not operate on the real child. The compiler child was stopped manually when identified; its observed RSS was approximately 6.3 GiB. The run produced 892 aggregate checkpoints before termination, but it did not complete final staged IR, clang assembly, link, or executable smoke. It is explicitly not a Doctrine success.

At the last valid aggregate checkpoint, the Doctrine counters were approximately: `str_alloc=3,617,989,315`, `str_reclaim=2,796,132,296`; `tagged_alloc=12,435,269`, `tagged_reclaim=126,851`; `arr_alloc_total=360,663,549`, `array_reclaim=320,614,571`; `bucket_alloc=8,495,199`, `bucket_reclaim=3,630,702`; `rc_retain=1,126,257,131`, `rc_release=887,476,064`; and `cc_reclaim=46`. The cumulative gaps were therefore approximately 821.9 million strings, 12.3 million tagged allocations, 40.0 million array buffers, 4.86 million buckets, and 238.8 million net object/vec retain operations. The counters strongly support sustained compiler-runtime retention during Doctrine emission, but they do not yet prove which ownership boundary is incorrect.

No compiler, clang, or Doctrine process remains active after the manual stop, the audit compiler binary was restored, temporary manifest/runner files were removed, and `git diff --check` is clean. The next work item is a precise ownership audit of tagged object/array/string release paths using these counters, together with a corrected descendant-aware physical-footprint monitor. Another large Doctrine run should not begin until that audit yields a concrete, minimal compiler-only lifetime fix or a smaller representative reproducer.


## Array retain/release call telemetry refinement

To separate valid array ownership operations from null/tag-bail invocations, the diagnostic runtime was extended with `array_retain_rc` and `array_release_rc`. The counters are incremented only after the array tag check and, for retains, immediately before the actual rc increment; for releases, immediately before the actual rc decrement. Entry-call counters remain available separately because they reveal how much traffic is rejected by null, address, or tag guards.

The g106→g107 generation reached an exact LLVM-IR fixpoint with SHA-256 `47e3e9a9e8fe5f8186a7cb998c374f31f0466b2e5afbdc58d822bf2cde16d2ef`. The final diagnostic compiler contained 35 labels, five actual-retain hook sites, and eight actual-release hook sites. Its self-host snapshot reported `array_retain_rc=32,270,507` and `array_release_rc=256,222,485`, while entry-call counters were `array_retain_call=35,348,611` and `array_release_call=605,419,994`.

These ratios are not yet a proof of over-release. The emitted compiler links against a prebuilt ABI8 stdlib object and the runtime surface contains multiple unified/legacy/linkonce paths; therefore allocation counters and operation counters must be partitioned by runtime family before they can be compared as a single ownership equation. The numbers do, however, establish that array release traffic is much larger than raw allocation traffic and that a future audit should trace the source of repeated valid release operations, especially `__mir_drop_by_repr`, `__mir_cell_drop`, own-element symmetric variants, and compiler-owned array fields.

The final ABI8 semantic gate for g107 passed 11/11 focused cases. The Cache roots build, clang/link path, and executable smoke also passed with the expected reachability output. No additional Doctrine run was performed after this instrumentation refinement.


## Flavor-partitioned array ownership evidence

The final g112→g113 diagnostic generation reached an exact LLVM-IR fixpoint with SHA-256 `f31138f8ff7d63061d8c7a02eeab6a923be7cc9435926b771c80f8f530567965`. It exposes 48 aggregate labels, including per-flavor actual array RC operations. In the self-host snapshot, the release side was dominated by `array_release_own_obj=243,945,120`, followed by `array_release_buf=6,669,497`, `array_release_repr=4,175,678`, `array_release_str=1,156,174`, and small cell/own-string/own-cell counts. The corresponding explicit array retain flavors were `array_retain_obj=31,787,421`, `array_retain_buf=361,165`, `array_retain_str=62,097`, `array_retain_cell=68,038`, and `array_retain_repr=10,859`.

This asymmetry is not by itself an over-release proof. `ownel_*` releases are deliberately selected for locals whose ownership comes from an owned producer (array literal, call return, or union) or from a proven retaining property snapshot. An owned producer already carries per-element references; it need not call the ordinary `array_retain_*` runtime helper. Conversely, `array_release_ownel_*` walks those element references on every release, not only when the buffer rc reaches zero. Therefore the next denominator must include array producer element-retain operations and per-element drop operations, partitioned by `obj`, `str`, `cell`, and runtime-repr paths. A single equation between `array_retain_obj` and `array_release_own_obj` would be incorrect and could motivate an unsafe double-free fix.

The existing caller-side policy confirms this boundary: `rcReleaseFlavor()` appends the `own` suffix only for locals accepted by `collectOwnElemLocals()`, where the retain/release flavor contract is checked and transferred or element-shared locals are excluded. The conservative leak-safe guards remain in force. No ownership behavior was changed as part of the flavor telemetry.


## g116–g126 targeted compiler optimizations

A compiler-only body eviction candidate was implemented and reached exact self-host fixpoint at g115→g116, SHA-256 `73178f1bfcfdfd700b955bc9e9084089cebe28d241c97000ecd3fd156c2e88df`. `FunctionDef::$body` and `FunctionEmitFrame::$body` now have an exact internal-class old-value release policy, and the frame body is detached after materialization. Focused ABI8 and Cache gates passed. A stronger follow-up removes each already-emitted `FunctionDef` from `Module::$functions` after all module-wide pre-scans; g119→g120 reached exact fixpoint, SHA-256 `2c5153247d7d2a69eed5636536da7ee947237e356a5552dcb7033d02ac270ab8`, and g120 passed ABI8/Cache gates.

The direct PID-aware same-target self-host comparison showed only a small effect: g113 baseline reached a sampled 963.4 MB physical footprint and 1,149,440 KB RSS, while g120 reached 961.9 MB and 1,147,936 KB RSS. This is a safe cleanup and may matter more on Doctrine's larger function population, but it is not the dominant retention fix.

Two adaptive builder changes were then added without changing emitted instruction ordering: `emitBagReadByClassId()` and `emitErasedIfaceCall()` now assemble switch tables and arm bodies as chunks, and `visitBlock()` uses chunks only for blocks with at least 256 statements while preserving the scalar fast path for small blocks. g121→g122 reached exact fixpoint with SHA-256 `e9e43b4551824d776cb0c8fad38e8ccbc5d6054bca8f0b370934539cee325f82`; g123→g124 reached exact fixpoint with SHA-256 `1be7dac45f3e773478aa80dd8b45393e2538cac5acb254d65b9116df65649464`; g125→g126 reached exact fixpoint with SHA-256 `b9924a16637c990bcb2c15cabd062acac5d1c1b6ede72d145800c777ab02f6e9`. Each candidate passed the focused ABI8 11-case suite and Cache build/link/smoke.

A same-target PID-aware comparison of g113 against g126 measured 966.4 MB physical and 1,152,528 KB RSS for g113 versus 962.1 MB and 1,147,584 KB RSS for g126, approximately a 0.4% RSS improvement. These builder optimizations are therefore retained as low-risk wins, but they do not account for the previously observed multi-GiB Doctrine late-Emit retention. The next high-impact step is a true compiler-only staging boundary or compact AST/metadata representation, preceded by correct process-tree physical monitoring and producer/element ownership attribution. No new Doctrine workload was launched after the unsafe PID-monitor run.


## g127–g135 file-backed emission boundary

The next architectural change moved the alloca-hoist boundary into the staged body path. `append_file_path()` now copies staged IR with a bounded 1 MiB libc buffer instead of materialising the complete `.bodies` file through `file_get_contents()`. `HoistAllocas::runFile()` then rewrites staged LLVM without `explode()`/`implode()` over the complete body: it keeps one bounded input line, spools static allocas and the remaining body to temporary files, appends them in canonical order, and removes the temporary files. The implementation was corrected for two cases present in real emitted output: extern functions are one-line `declare` items, and one MIR function can emit multiple LLVM definitions when a generator includes its `$resume` continuation. The final adaptive path uses the file-backed route only for bodies at least 262,144 bytes and retains the in-memory route for smaller bodies to avoid one filesystem round-trip per ordinary function.

The adaptive g134→g135 self-host reached an exact LLVM-IR fixpoint. Both generated LLVM files have SHA-256 `bd490336d5c8cb0e820d77de1762a4296527081b4c234b3519bc2ad5cf3093f6`, `cmp` returned zero, and the linked compiler artifact was `/tmp/manticore-g135-filehoist-adaptive-1787848952/manticore-g135` with size 10,104,912 bytes. The earlier multi-definition-only generation also reached an exact fixpoint with SHA-256 `89c59996c5ffee2cfea62a8030b5e57dd1d28637130d5749026983bb67d87f72`; it is superseded by the adaptive generation.

The final g135 semantic gates passed. The focused ABI8 set passed 11/11: eight dynamic-method cases plus `is_callable`, callable forms, and full closure binding. The Symfony Cache roots manifest completed the staged body build, clang assembly, link, and executable smoke. The output remained `psr=0`, `cache=1:redis=1:orm=0`, T1–T6 root counts `0/20`, `1/32`, `1/36`, `1/44`, `1/58`, `1/70`, and `cache=1:redis=1:trait=1`. No `.bodies.fn.*`, `.allocas`, `.rest`, or `.hoisted` temporary fragments remained after the run, and `git diff --check` passed. These gates establish semantic and staging correctness; they do not by themselves establish a Doctrine footprint reduction.

A single corrected direct-PID Doctrine diagnostic was then run with the same Redis/Doctrine manifest family, `MANTICORE_POOL=0`, `MANTICORE_MEMORY=rc`, `MANTICORE_PHASE_RECLAIM=1`, the matching ABI8 stdlib object/signature, and an 8 GiB safety cap. The compiler binary itself was the background process rather than an `/usr/bin/time` wrapper. The real compiler PID was 76533; the monitor collected 56 physical/RSS samples over 340 seconds and captured both `vmmap -summary` and `sample` for that PID at the cap. The process reached the safety ceiling and was terminated with signal 15 before staged LLVM finalisation, clang assembly, link, or executable smoke, so this remains a capped diagnostic and is not a Doctrine success.

| g135 adaptive direct-PID diagnostic | Value |
|---|---:|
| Maximum sampled RSS | 9,744,832 KB, approximately 9.29 GiB |
| Last sampled physical footprint | 9.8G, approximately 9.80 GiB |
| Safety condition | RSS reached 9,978,707,968 bytes; cap was 8 GiB |
| Elapsed time to cap | 340 seconds |
| Final artifact | Not produced/verified |
| Doctrine verdict | Capped diagnostic; no successful build claim |

The monitor correction makes this physical measurement valid for the compiler process, but it is not a controlled before/after improvement measurement: the previous 6.3 GiB observation was a manually stopped RSS reading from a different, wrapper-confounded run and was not a matching peak sample. The result therefore shows that the adaptive sink is semantically safe and that late compiler retention still exceeds the 8 GiB safety budget, but it does not justify claiming a material Doctrine reduction. The next high-value direction is a compact/frozen MIR or rope-like emission representation that prevents the large per-function LLVM string from being fully constructed before the sink boundary; further local chunk conversions or blanket ownership releases are not justified by this evidence.

No full repository suite was run. No compiler, clang, or Doctrine process remains active after the capped diagnostic.


## g136–g137 per-statement MIR detachment follow-up

Because the adaptive file-backed sink remained above the safety budget, a further compiler-only lifetime boundary was tested. In an ordinary non-generator function, `EmitLlvm` now emits each top-level MIR statement's ordered warning/body/release fragments and immediately detaches that statement from the function block. All module-wide scans and per-function collection passes are complete before this loop, and the detachment does not alter emitted instruction order or target ownership. The earlier multi-definition file hoister, the 262,144-byte adaptive threshold, and ABI8 runtime path remain unchanged.

The g136→g137 self-host reached an exact fixpoint. The two LLVM files have SHA-256 `ec30658bab17f7da912b3a00e875d735538e43fe2543a33c6756d668b86f6145`, `cmp` returned zero, and the linked compiler artifact was `/tmp/manticore-g137-stmtdetach-1787849944/manticore-g137` with size 10,104,912 bytes. The focused ABI8 suite passed 11/11, and the Cache roots target completed staged build, clang assembly, link, and smoke with the expected Redis reachability and T1–T6 outputs. No staged temporary body fragments remained and `git diff --check` passed.

A justified same-target capped Doctrine comparison was run once with g137. The compiler was again the direct background PID, with the same manifest family, matching ABI8 stdlib, `MANTICORE_POOL=0`, `MANTICORE_MEMORY=rc`, `MANTICORE_PHASE_RECLAIM=1`, and an 8 GiB safety cap. It reached the cap in 346 seconds at PID 84035 and was terminated before final staged LLVM, clang, link, or executable smoke. This is a capped diagnostic, not a Doctrine success.

| Direct-PID capped comparison | g135 adaptive | g137 statement-detach |
|---|---:|---:|
| Peak sampled RSS | 9,744,832 KB | 9,741,712 KB |
| Peak sampled RSS, approximate | 9.29 GiB | 9.29 GiB |
| Peak sampled physical footprint | 9.8G | 10.0G |
| Elapsed to cap | 340 s | 346 s |
| Final linked Doctrine artifact | None | None |

The result is **not a material improvement**: RSS is effectively unchanged within sampling/phase variance, while the observed physical peak was slightly higher for g137. The statement detachment is retained as a semantically safe cleanup, but it is not the dominant retention term. The next implementation should therefore avoid further local release or chunk experiments and move below `emitFunction()`'s string-return contract: either a frozen/compact MIR representation that releases the original graph before lowering, or a true append-only LLVM rope/sink whose nested emitters never construct the full fat-function string before the staged boundary. Any future Doctrine run should wait for that architectural change and its ABI8/Cache gates.

No compiler, clang, or Doctrine process remains active after the capped diagnostic.


## g138–g139 append-only FunctionTextSink result

The next boundary was moved below `EmitLlvm`'s ordinary `emitFunction()` string materialisation. A new compiler-only `FunctionTextSink` writes the header and emitted fragments to an ordinary chunk list while the function is small, then flushes once to a raw FILE* after 262,144 bytes. Subsequent fragments bypass the large PHP string. `finish()` returns either the exact in-memory text or a private file marker; the staged driver consumes that marker with `HoistAllocas::runFile()`, appends the rewritten file to `.bodies`, and removes both temporary files. Generator, FFI-wrapper, `__main`, and library-special paths retain their existing string fallback, so the first implementation changes only ordinary functions with the known fat-function risk profile.

The g138→g139 self-host reached an exact fixpoint. Both LLVM files have SHA-256 `a745c643f713ce08295ff3711d9d14605fe733323d347b8f4af01f6cc73bc2f1`, `cmp` returned zero, and the linked compiler artifact was `/tmp/manticore-g139-function-sink-1787851728/manticore-g139` with size 10,122,064 bytes. The focused ABI8 suite passed 11/11. The Cache roots target completed staged build, clang assembly, link, and executable smoke with the expected Redis reachability and T1–T6 outputs. No raw sink or hoist temporary files remained and `git diff --check` passed.

A same-target direct-PID Doctrine comparison was run with g139 using the same manifest family, matching ABI8 stdlib, `MANTICORE_POOL=0`, `MANTICORE_MEMORY=rc`, `MANTICORE_PHASE_RECLAIM=1`, and an 8 GiB safety cap. The compiler was PID 93881 itself; `vmmap` and `sample` were collected for that PID. It still hit the safety ceiling before final staged IR, clang, link, or executable smoke, so it remains a capped diagnostic rather than a successful Doctrine build. However, relative to the immediately preceding g137 statement-detach run, the memory curve improved materially at the same cap window.

| Direct-PID comparison | g137 statement-detach | g139 FunctionTextSink | Delta |
|---|---:|---:|---:|
| Peak RSS | 9,975,513,088 B / 9.290 GiB | 9,263,235,072 B / 8.627 GiB | **−7.14%** |
| Peak physical footprint | 10.0 GiB | 8.6G / 8.600 GiB | **−14.00%** |
| Elapsed to cap | 346 s | 347 s | comparable |
| Final Doctrine artifact | None | None | capped before link |

The physical values above are the maximum direct-PID `vmmap` samples: g137 reached exactly 10.0G, while g139 reached 8.6G (`9,234,179,686` bytes). The improvement is therefore evidence for a real reduction in the late-emission memory curve, not merely an allocator cleanup counter change, but it is not yet a complete Doctrine compile. The sink should remain enabled. The next step is to inspect the g139 phase curve and, if the curve remains below the safety ceiling under a slightly higher controlled cap, allow one carefully bounded run to determine whether final IR staging can now complete; no unbounded run is justified.

No compiler, clang, or Doctrine process remains active after the capped diagnostic.


## g139 cap12 follow-up

To test whether the measured g139 reduction was sufficient to cross the staged-IR boundary, one additional direct-PID run used the same Doctrine manifest and a controlled 12 GiB cap. The compiler reached a native `EXC_BREAKPOINT`/`SIGTRAP` in `libsystem_malloc` from `__mir_alloc_tagged` after 287 seconds, at approximately 4.2 GiB RSS and 3.9 GiB physical footprint. It did not reach the configured cap and produced no final IR, clang object, linked executable, or smoke result. The crash report is preserved in the evidence directory. Because Cache gates and the earlier capped g139 run both passed through the new sink path, and this run stopped inside the native allocator under substantially different pressure, the event is recorded as an allocator-pressure diagnostic rather than attributed to a proven FunctionTextSink data-corruption bug. No blind retry was performed.

This reinforces the operational boundary: g139's append-only sink is a material improvement at the 8 GiB comparison point, but full Doctrine completion still requires a deeper reduction in per-statement/nested-fragment materialisation and a more robust allocator-pressure strategy. The 12 GiB cap is not evidence of a successful Doctrine build.


## g139 cap12 crash forensic and g142 object-layout fix

The saved cap12 report initially looked like a native allocator trap, but the MallocStackLogging reproduction and the g137 control run localized the underlying failure more precisely. Both g137 and g139 failed at the same compiler-generated `__mir_array_cow_str` site inside `manticore_Compile_Mir_Passes_EmitLlvm__dynamicMethodZeroArgHelper`, with invalid noncanonical array addresses. This made the FunctionTextSink an unlikely cause: the control predates that sink.

The relevant LLVM helper allocated four compiler state objects with `__mir_alloc_tagged(i64 16)` and then used fields beyond the allocation. The exact filtered object trace showed the problematic NewObj keys were `Compile\\Mir\\Passes\\SsaBuilder`, `Compile\\Mir\\Passes\\LocalSlots`, `Compile\\Mir\\Passes\\ControlFlow`, and `Compile\\Mir\\Passes\\ArenaContext`, while the real classes and method calls were `Compile\\Mir\\SsaBuilder`, `Compile\\Mir\\LocalSlots`, `Compile\\Mir\\ControlFlow`, and `Compile\\Mir\\ArenaContext`. `EmitLlvmObjects.php` is in namespace `Compile\\Mir\\Passes` and had no imports for these four classes; unqualified `new` expressions therefore lowered to non-existent Passes classes. The emitter treated the missing ClassDef as an unknown object, allocating only 16 bytes with descriptor zero. Subsequent field writes corrupted adjacent memory, and the next array COW operation faulted on the corrupted pointer.

The fix is source-level and ABI-neutral: `EmitLlvmObjects.php` now explicitly imports `SsaBuilder`, `LocalSlots`, `ControlFlow`, and `ArenaContext` from `Compile\\Mir`. The existing canonical `NewObj` lookup remains in place, but no allocator or target ABI change was needed.

The corrected generated helper allocates sizes 40, 72, 64, and 72 respectively. g141→g142 reached an exact LLVM fixpoint with SHA-256 `97207242b11a4f63133bf4e05f8fefc517dd56ede8dd29836b97d47dd97dc606` and `cmp=0`; clang and link completed successfully. Native expected-output ABI8 fixtures passed 11/11, and the Cache roots manifest build/link/smoke passed with `psr=0`, `cache=1:redis=1`, trait reachability, and T1–T6 outputs unchanged.

A single direct-PID g142 Doctrine diagnostic was then run with the same g139 manifest family, matching ABI8 stdlib, `MANTICORE_POOL=0`, `MANTICORE_MEMORY=rc`, and a 12 GiB RSS cap. The user manually stopped it after observing continued retention. The compiler exited with signal 15, reached peak RSS 9,061,312 KB, and produced no crash report; the run did not complete final staged IR/clang/link/smoke and is therefore a capped diagnostic, not a Doctrine success. The fix removes the proven heap-corruption trigger, but does not by itself solve the dominant compiler lifetime retention. Raw evidence is in `docs/status/evidence/g139-function-sink-cow-root-analysis.md` and `/tmp/manticore-g142-doctrine-1787895448` on the connected macOS host.


## g143–g151 root attribution and compact emission caches

The first root snapshots established that the module function table is not the late-Emit retention root: during self-host emission, `module_fns` drained from 4,438 to 0 while aggregate emitter caches grew to 35,806 entries. The per-cache breakdown localized the dominant table to `resolveMethodClassCache` (29,085 entries at post-function emission), followed by `classImplementsCache` (1,588), `mangleCache` (4,466), and smaller interface/descriptor/property tables. Lazy property and dynamic-method helper registries remained empty in the compiler self-host workload, with `helper_bytes=0`.

A compiler-only opt-in boundary was added as `MANTICORE_COMPACT_CACHES=1`. At each 1,024-function emission boundary it clears only pure memoization tables: method resolution, mangling, inheritance/interface predicates, descendant lookup, and property-holder/name caches. It does not clear helper bodies, signatures, class metadata, module globals, MIR target values, or runtime allocator state. With compact mode enabled, self-host snapshots showed zero cache entries immediately after each batch and only 1,862 entries regenerated by the post-function/helper phase.

Root telemetry initially enabled the full compile-time Stats path and reproduced a Stats-sensitive `HoistAllocas::runFile()` failure on the Cache target. The diagnostic logger was separated from `Stats::$on`: `MANTICORE_ROOT_TRACE=1` now uses an independent clock and does not alter ordinary Stats instrumentation or emission behavior. This restored a successful telemetry-enabled Cache build and avoided conflating diagnostic instrumentation with staging correctness.

The updated self-host chain g150→g151 reached an exact fixpoint. Both LLVM files have SHA-256 `d6b7b01d6acae29cff107f7e8949ece7d5eac3f022da2c90416e0bf6063ca450`, `cmp` returned zero, and clang assembly plus stub linking completed. The current g150 compiler passed the focused ABI8 expected-output gate 11/11: eight dynamic-method fixtures, `callable_dynamic`, `callable_forms`, and `byref_closure_bound_scope`, using the matching ABI8 stdlib object/signature, `MANTICORE_POOL=0`, `MANTICORE_MEMORY=rc`, and `MANTICORE_PHASE_RECLAIM=1`.

The Symfony Cache roots manifest completed staged build, clang assembly, link, and executable smoke with both `MANTICORE_ROOT_TRACE=1` and `MANTICORE_COMPACT_CACHES=1`. Output remained `psr=0`, `cache=1:redis=1:orm=0`, T1–T6 root counts `0/20`, `1/32`, `1/36`, `1/44`, `1/58`, `1/70`, and `cache=1:redis=1:trait=1`. The unique-prefix cleanup check found only the expected output binary; no `.fnraw.*`, `.bodies`, `.allocas`, `.rest`, or `.hoisted` files were created under that prefix. A no-roottrace g148 control also completed the same Cache build and smoke, confirming that the default emission path remains unaffected.

No Doctrine benchmark was run after this boundary. The cache release is validated as a compiler-only, ABI8-preserving candidate, but its material RSS/physical-footprint effect on the heavy Doctrine target remains unmeasured. The next justified action is one direct-PID, low-cap Doctrine diagnostic using g150, with root snapshots enabled and the same manifest/configuration as the earlier comparisons; it should proceed only after the current change is committed and the final worktree diff is reviewed.


## g150 bounded Doctrine diagnostic after cache compaction

One direct-PID diagnostic used g150 with `MANTICORE_COMPACT_CACHES=1`, `MANTICORE_ROOT_TRACE=1`, `MANTICORE_POOL=0`, `MANTICORE_MEMORY=rc`, `MANTICORE_PHASE_RECLAIM=1`, and the matching ABI8 stdlib object/signature. The compiler reached the late emission phase and produced root checkpoints through batch 19,456. At every completed emission batch, all compacted memoization-cache counts remained zero; the only observed growth was in explicitly retained helper state, reaching `dyn_helpers=1,291`, while module functions continued to drain.

The run was safety-stopped after 452 seconds when the external observation showed approximately 32 GiB physical pressure. The last captured `vmmap -summary` reported `TOTAL VIRTUAL 38.9G`, `RESIDENT 3.3G`, `DIRTY 3.2G`, and `SWAPPED 26.9G`; the corresponding direct compiler RSS samples reached approximately 8.8 GiB before the process was terminated. This confirms that compacting the named resolution/mangling/property memoization tables does not remove the dominant late-Emit physical/swapped footprint. The compiler exited 143, produced no final staged IR, clang object, linked executable, or smoke output, and this is not a Doctrine success. No compiler or harness process remained afterward.

The result narrows the next target: the remaining pressure is not the retained pure resolution-cache tables. It is likely high-volume body/helper materialization and/or allocator fragmentation around the active erased dynamic helper registry (`dyn_helpers=1,291`) and large staged text lifetime. No further cache flushes should be attempted without a more specific ownership boundary.


## g152 helper-body streaming lifetime boundary

The next root-level change targets the remaining compiler-owned dynamic/property helper text rather than the already-compacted resolution caches. In streaming mode, closure/object/inclusion bodies are still appended through the existing staged path, while each lazily generated property or erased dynamic-method helper is now written to a private temporary file, processed by `HoistAllocas::runFile()`, appended directly to the staged body file, and immediately released from its PHP string/registry slot. The helper bodies are emitted in the same order as before; this is a compiler lifetime change only and does not alter ABI8, target allocation, PHP string COW, or runtime dispatch semantics.

The source bootstrap g151→g152 and exact g152→g153 fixpoint completed. Both LLVM outputs have SHA-256 `bd82b6cb124867219a341108f46950c14eb1def634060a4dd1cebaacfd246148`, with `cmp=0`; clang and stub linking passed. The focused ABI8 gate passed 11/11, and the Cache roots target completed staged build, clang, link, and smoke with the expected PSR/Redis, T1–T6, and trait-reachability output. The Cache target has no generated helper bodies, so it validates ordering and regression safety but does not measure the intended Doctrine-specific memory reduction.

The earlier g150 Doctrine diagnostic showed the problem this change is intended to address: at batch 16,384 the dynamic helper registry reached 1,291 entries while compacted memoization caches remained zero; the run later reached approximately 32 GiB physical pressure and was safety-stopped before final IR/link. The g152 helper-streaming candidate has not yet received a new Doctrine measurement. Therefore no quantitative Doctrine improvement is claimed until one direct-PID bounded comparison completes with final staged IR, clang, link, and smoke or is explicitly recorded as capped.


## Measured g152 Doctrine comparison after helper-body streaming

A bounded direct-PID Doctrine run used the same g139 manifest family, matching ABI8 stdlib, `MANTICORE_POOL=0`, `MANTICORE_MEMORY=rc`, `MANTICORE_PHASE_RECLAIM=1`, `MANTICORE_ROOT_TRACE=1`, and `MANTICORE_COMPACT_CACHES=1`. The g152 compiler reached the same emission checkpoint as the prior g150 diagnostic, batch 19,456, with `module_fns=5,077` and `dyn_helpers=1,291`; compacted memoization caches remained zero.

The direct compiler was safety-stopped at 11,699,232 KB RSS after the external vmmap sample reached `TOTAL VIRTUAL 18.4G`, `RESIDENT 7.6G`, `DIRTY 7.5G`, and `SWAPPED 8.2G`. It exited 143 and did not produce final staged IR, clang object, linked executable, or smoke output. This is a bounded diagnostic, not a Doctrine success.

Compared with the previous g150 run at the same batch 19,456 checkpoint, whose last vmmap sample was `TOTAL VIRTUAL 38.9G`, `RESIDENT 3.3G`, `DIRTY 3.2G`, and `SWAPPED 26.9G`, g152 reduced observed virtual footprint by approximately 52.7% and swapped footprint by approximately 69.5% at the comparable checkpoint. Resident memory was higher (7.6G versus 3.3G), indicating that the change materially reduced swap/virtual allocator growth rather than simply reducing all live RSS. The helper-body streaming boundary is therefore a material improvement, although it has not yet crossed the full Doctrine completion boundary.
