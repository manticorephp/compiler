# tools/prof — profiling the compiler with the host's own tools

No compiler instrumentation. `src/` is not touched by any of this: the binary
already carries its full symbol table (nothing is stripped) and
`MANTICORE_STATS=1` already prints a per-pass timeline, which is everything a
host profiler needs to be useful.

| script | question it answers | host tool |
|---|---|---|
| `scale.sh` | does peak RSS track input size? | `/usr/bin/time` |
| `cpu.sh` | which PHP function burns the wall clock? | macOS `sample` |
| `live.sh` | which PHP function holds the peak live set? | macOS `MallocStackLogging` + `heap` |
| `../docker/prof/run.sh` | the same, on Linux | `heaptrack` in the gate toolchain image |
| `report.php` | folds any of the above into PHP names | — |
| `propleak.php` | is an rc value in a PROPERTY ever released? (no) | `/usr/bin/time -l` |

Granularity is **the function, forever**: `-g` is never passed to clang
(`Main.php:526`), so the binary has no DWARF and no line numbers exist to
attribute to.

CALLERS, on the other hand, are now reachable. `MANTICORE_FRAME_POINTERS=1`
splices `"frame-pointer"="all"` into every emitted function, so `sample` and
`malloc_history` can unwind out of a leaf; `live.sh --build` sets it alongside
`MANTICORE_POOL=0`. Without it `malloc_history` folds every stack onto
`__mir_realloc_tagged` / `__mir_array_index_build` and the question "which pass
HOLDS this" has no answer. `clang -fno-omit-frame-pointer` does not substitute
for it — measured, the driver flag changes nothing for `-x ir` input.

Naming the caller of a live block:

```bash
bash tools/prof/live.sh --build                # no-pool + frame-pointer compiler
# start the target under MallocStackLogging=1, then, at the peak:
malloc_history <pid> -allBySize >mh.txt        # ~12 s, ~380 MB of stacks
```

Then tally the frame above the allocator — that is what turned "55% of the peak
is string-keyed array machinery" into "69.7% of it is `InferTypes::mergeLocals`".

## Reading `report.php`

```
php tools/prof/report.php sample    <sample.txt>  [top]
php tools/prof/report.php callers   <sample.txt>  <symbol> [top]
php tools/prof/report.php heap      <heap.txt>    [top]
php tools/prof/report.php malloc    <snap.txt>    [top]
php tools/prof/report.php heaptrack <peak.txt>    [top]
php tools/prof/report.php phase     <stats.txt>   <elapsed-ms>
```

Two things it does that a raw profiler dump does not:

- **demangles** `manticore_Compile_Mir_Passes_InferTypes__inferNode` back to
  `Compile\Mir\Passes\InferTypes::inferNode`. The forward mangling is lossy
  (`\` → `_`), so this is a documented heuristic, not an inverse.
- **charges an allocation stack to its deepest non-plumbing frame.** Without
  that fold every site in the program collapses onto `__mir_alloc_tagged`.
  `PROF_NOFOLD=1` turns the fold off — that is the negative control, and it must
  produce a useless report.

`callers` prints a `truncated` count first. Leaf routines are compiled without a
frame record (`-fno-omit-frame-pointer` is never passed either), so a large part
of the samples under such a routine have no caller in the file at all. Those
rows are a biased sample: read them for direction, never as a share.

## Self-test

`fixture.php` is a program whose live set has a known owner and size:

```bash
MANTICORE_POOL=0 ./bin/manticore.nopool compile tools/prof/fixture.php -o /tmp/fixture
MINMB=8 SNAPS=1 OUTDIR=/tmp/fx BIN=/tmp/fixture bash tools/prof/live.sh -- 1000000
```

⚠ `MANTICORE_POOL=0` on that **compile** line is not redundant. The flag is read
from the environment of whichever compiler process is running and is baked into
the IR it emits — a pool-free compiler still emits a POOLED program unless the
variable is set again for that compile. Without it the fixture reports one 1 GiB
`__mir_pool_alloc` block and nothing else, which is how this was found.

The report must rank `fixtureAllocs` first with ~N blocks. If it names
`__mir_alloc_tagged` or `__main` instead, the harness is broken (or clang
inlined the fixture — which is why the fixture has two call sites).

## Cost knobs

- `live.sh` needs a **no-pool compiler** (`live.sh --build`): the small-object
  pool carves objects out of one 1 GiB `mmap`, and no malloc-level profiler can
  see inside it. `MANTICORE_POOL=0` is a **compile-time** flag baked into the
  emitted IR, so it must hold for the whole build — hence a worktree of its own,
  never the main checkout.
- `heap` is the cheap path and the default (seconds per snapshot, `EVERY=10s`,
  `MINMB=128`); it names the ALLOCATING function. `malloc_history <pid>
  -allBySize` names the CALLER instead and costs minutes per dump on a multi-GB
  process — run it by hand when that is the question, and feed it to
  `report.php malloc`.
- `cpu.sh` runs the **default** build. A no-pool binary's timings are not this
  compiler's timings.
