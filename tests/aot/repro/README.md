# Reproducers for OPEN bugs

Not part of the suite (no `expected/`, so `run.sh` skips them) — these are the smallest
programs that still show a bug we have NOT fixed. Keep them building.

`bash tests/aot/xfail.sh [-k <substr>] [-j 0] [-v]` runs every `<name>.php` here that has
the php oracle output beside it as `<name>.expected`, and inverts the verdict: `XFAIL`
is a bug still open, `XPASS` is one that got fixed and fails the run until the repro
is promoted (`git mv` into `cases/` + `expected/<name>.out`) and its issue closed.
A repro names its issue in a comment — `// issue: #123` — and the issue body carries
the same source. No `.expected` (a superset repro with no Zend oracle) ⇒ `SKIP`.

## w4/ — the W4 value-channel producers (EMPTY: the epic is closed)

One repro per producer that stores a RAW word into a `cell` channel, with the php
oracle output beside it as `<name>.expected`. `bash tools/w4_repros.sh` is their gate;
a passing one is promoted into `cases/` + `expected/`. Every producer P1–P7 is
closed and promoted, so the directory is empty and the gate reports it — a NEW
one goes here. See `docs/design/value-channels.md`.

## wrong/ · crash/ · leak/ — one file per open issue

`wrong` runs and prints something php does not; `crash` is a SIGSEGV / SIGBUS or invalid
IR; `leak` measures its own peak RSS around a loop and prints `flat` or `LEAK`. Line 2 of
a repro is its title, line 3 its `// issue: #N`. `bash tools/file_bug.sh <repro>` files
the issue and writes the number back — the whole flow is in `AGENTS.md`, "Reporting a
bug you are not fixing now".

## await_park_wrong_value.php / await_park_wrong_value_noio.php

A task that PARKS inside a callee can hand back the WRONG value through `Task::await()`:
a `fread($c, 5)` reader returns the int **5** (its own length argument) instead of the
string, printing `string(5) "5"` with `strlen() === 1`. The `_noio` variant needs no
sockets at all — just `delay()` inside a callee.

See the memory note `await-result-wrong-value-2026-07-26` for everything already ruled
out (each half of the shape is clean in isolation) and where to look first.
