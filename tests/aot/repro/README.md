# Reproducers for OPEN bugs

Not part of the suite (no `expected/`, so `run.sh` skips them) — these are the smallest
programs that still show a bug we have NOT fixed. Keep them building.

## await_park_wrong_value.php / await_park_wrong_value_noio.php

A task that PARKS inside a callee can hand back the WRONG value through `Task::await()`:
a `fread($c, 5)` reader returns the int **5** (its own length argument) instead of the
string, printing `string(5) "5"` with `strlen() === 1`. The `_noio` variant needs no
sockets at all — just `delay()` inside a callee.

See the memory note `await-result-wrong-value-2026-07-26` for everything already ruled
out (each half of the shape is clean in isolation) and where to look first.
