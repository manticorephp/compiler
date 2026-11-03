# Profiling a compiled program

A Manticore binary carries its full symbol table and no DWARF, so a host profiler
sees **functions** (PHP functions, methods and closures keep readable names) and
nothing finer. That is enough to answer "which part of a request costs the most".
This page covers a compiled **server**; `tools/prof/README.md` covers the
compiler itself.

## One command (macOS)

```bash
bash tools/prof/serve.sh --build examples/symfony-app /   # first run: builds bin/app.prof
bash tools/prof/serve.sh examples/symfony-app /api/items  # later runs: any route
```

It starts `bin/app.prof` with one worker, loads the route with
[`wrk`](https://github.com/wg/wrk), samples the worker for `SECS` seconds with the
system `sample`, and prints two tables for the worker's main thread.

| variable | default | meaning |
|---|---|---|
| `PORT` | `8199` | listen port |
| `SECS` | `10` | sampling window |
| `CONN` | `32` | `wrk` connections |
| `TOP` | `40` | rows per table |
| `KEEP` | `0` | `1` keeps the raw `sample` output and prints its directory |

## Reading the output

- **SELF** counts the samples whose top frame is the function. It names the leaf
  work: `strcmp`, `malloc`/`free`, `__mir_array_index_find`, cell retain/drop,
  `writev`.
- **INCLUSIVE** counts a function together with everything it calls, as a share
  of the thread, so rows overlap and a caller always outranks its callees. This is
  the table that answers "how much of a request is `Kernel::handle`". A function
  that is already open higher on the same stack (recursion) is counted once.

Names are folded back from the mangled symbols by `tools/prof/report.php`
(`Symfony\Component\HttpKernel\Kernel::handle`, not
`manticore_Symfony_Component_HttpKernel_Kernel__handle`). The mangling is not
injective, so treat a demangled name as a reading, not a proof.

## Why a separate binary

`serve.sh` builds with `MANTICORE_FRAME_POINTERS=1`, which makes every emitted
function keep a frame record. Without it the unwinder cuts each stack at the first
function that has none, the tree has no useful root, and INCLUSIVE is wrong. The
cost is a few percent of throughput, so the numbers are **shares**, not absolute
requests per second; measure RPS on the normal `bin/app`.

## Pitfalls

- **Sample the worker, not the supervisor.** With `WORKERS>=1` the process you
  start is a supervisor waiting on its children; sampling it reports `99.9%
  __semwait_signal`. The script samples the child.
- **Idle pool threads dominate the plain table.** A pool blocked in `read(2)`
  shows as 82% of an all-threads table; `report.php thread` selects one thread
  (`main-thread` by default, the substring is the 4th argument).
- **One sample is one function, not one line.** For a line-level question read
  the generated IR (`manticore build --keep-ir`).
- **Noise.** Other processes on the machine move the numbers by several percent.
  Repeat before acting on a difference smaller than that.

## Linux

`sample` is macOS-only. On Linux use `perf record -g -p <worker>` (build with
`MANTICORE_FRAME_POINTERS=1` for the same reason) or the heaptrack route in
`tools/docker/prof`. `report.php` reads `sample` output only.
