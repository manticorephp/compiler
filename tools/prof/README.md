# tools/prof — profiling the compiler with the host's own tools

No compiler instrumentation. `src/` is not touched by any of this: the binary
already carries its full symbol table (nothing is stripped) and
`MANTICORE_STATS=1` already prints a per-pass timeline, which is everything a
host profiler needs to be useful.

| script | question it answers | host tool |
|---|---|---|
| `scale.sh` | does peak RSS track input size? | `/usr/bin/time` |
| `cpu.sh` | which PHP function burns the wall clock? | macOS `sample` |
| `live.sh` | which PHP function holds the peak live set? | macOS `MallocStackLogging` + `malloc_history` + `heap` |
| `../docker/prof/run.sh` | the same, on Linux | `heaptrack` in the gate toolchain image |
| `report.php` | folds any of the above into PHP names | — |

Granularity is **the function, forever**: `-g` is never passed to clang
(`Main.php:526`), so the binary has no DWARF and no line numbers exist to
attribute to.

## Reading `report.php`

```
php tools/prof/report.php sample    <sample.txt>  [top]
php tools/prof/report.php callers   <sample.txt>  <symbol> [top]
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
./bin/manticore.nopool compile tools/prof/fixture.php -o /tmp/fixture
OUTDIR=/tmp/fx BIN=/tmp/fixture bash tools/prof/live.sh -- 400000
```

The report must rank `fixtureAllocs` first with ~N blocks. If it names
`__mir_alloc_tagged` or `__main` instead, the harness is broken (or clang
inlined the fixture — which is why the fixture has two call sites).

## Cost knobs

- `live.sh` needs a **no-pool compiler** (`live.sh --build`): the small-object
  pool carves objects out of one 1 GiB `mmap`, and no malloc-level profiler can
  see inside it. `MANTICORE_POOL=0` is a **compile-time** flag baked into the
  emitted IR, so it must hold for the whole build — hence a worktree of its own,
  never the main checkout.
- `SNAPS=` caps `malloc_history` snapshots (each takes minutes on a multi-GB
  process). `RISE=` sets how much RSS must climb before another one is taken.
- `cpu.sh` runs the **default** build. A no-pool binary's timings are not this
  compiler's timings.
