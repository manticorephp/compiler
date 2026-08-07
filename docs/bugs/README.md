# Parked reproducers

A `.php` here is a program that does NOT match `php` today, kept out of
`tests/aot/cases/` on purpose: a permanently red suite stops being read. Each
one is paired with a `.expected` holding the php oracle, and is named in the
design doc that owns the gap.

Run one:

    bin/manticore compile docs/bugs/<name>.php -o /tmp/x && /tmp/x
    diff <(/tmp/x) docs/bugs/<name>.expected

Move it back into `tests/aot/cases/` + `tests/aot/expected/` the moment it
passes — that is the point of keeping the oracle next to it.

| reproducer | gap | owner |
|---|---|---|
| `erased_arith_float_cell` | a plain `mixed` cell in numeric arithmetic takes the integer path, so a cell holding `1.5` answers `int(1)` for `$v + 1`. Routing it to the promoting tagged path is correct and the self-build survives it — it needs a store-side un-cellify plant first (a concrete-scalar slot written with a cell value), or a loop-carried `int $pos` holds a boxed word while the loop condition compares it raw | `docs/design/unknown-cell-soundness.md` §18 |
