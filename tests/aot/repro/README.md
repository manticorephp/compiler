# Reproducers for OPEN bugs

Not part of the suite (no `expected/`, so `run.sh` skips them) — these are the smallest
programs that still show a bug we have NOT fixed. Keep them building.

## w4/ — the W4 value-channel producers (EMPTY: the epic is closed)

One repro per producer that stores a RAW word into a `cell` channel, with the php
oracle output beside it as `<name>.expected`. `bash tools/w4_repros.sh` is their gate;
a passing one is promoted into `cases/` + `expected/`. Every producer P1–P7 is
closed and promoted, so the directory is empty and the gate reports it — a NEW
one goes here. See `docs/design/value-channels.md`.

## compare/ — array comparison semantics

`array_identity.php`: `===` / `==` over two arrays compares the CARRIER WORDS, so
two equal arrays are `false` and a `mixed` holding one is `true` against anything
with the same pointer. This lived under `w4/` while the channel epic ran, but it
is not a channel bug — the words it compares are correctly-formed cells. It needs
php's per-kind comparison (recursive, key-order-sensitive for `===`), which is its
own epic.

## await_park_wrong_value.php / await_park_wrong_value_noio.php

A task that PARKS inside a callee can hand back the WRONG value through `Task::await()`:
a `fread($c, 5)` reader returns the int **5** (its own length argument) instead of the
string, printing `string(5) "5"` with `strlen() === 1`. The `_noio` variant needs no
sockets at all — just `delay()` inside a callee.

See the memory note `await-result-wrong-value-2026-07-26` for everything already ruled
out (each half of the shape is clean in isolation) and where to look first.
