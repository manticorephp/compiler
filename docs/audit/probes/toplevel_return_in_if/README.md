# probe: a non-entry top-level `return` inside an `if` kills the program

**Finding:** `toplevel-conditional-return-ends-program` (S1, T1, compiler-root).

`lower_module` flattens every file's top-level statements into one `__main`,
entry last, and drops a non-entry file's top-level `return` so an include-return
cannot terminate the program. That drop only recognises a `return` that IS a
top-level statement. A `return` nested inside an `if` survives the flatten and
ends `__main` — so **every statement after it, including the whole entry, never
runs.**

The shape is composer's, not a contrivance:

- `vendor/autoload.php` — `return ComposerAutoloaderInit…::getLoader();`
- `vendor/symfony/polyfill-intl-icu/bootstrap.php:19` —
  `if (\PHP_VERSION_ID >= 80000) { return require __DIR__.'/bootstrap80.php'; }`
- every other symfony polyfill bootstrap

## Reproduce

```bash
cd docs/audit/probes/toplevel_return_in_if
MANTICORE_PRELUDE=$PWD/../../../../prelude ../../../../bin/manticore build manticore.json
./smoke_bin; echo "rc=$?"          # prints NOTHING, rc=0
php -r 'require "tiers/a_boot.php"; require "tiers/entry.php";'   # prints ENTRY RAN
```

Expected (php): `ENTRY RAN`. Actual (manticore): no output, rc 0.

## Why it matters for the audit

It is the reason `docs/audit/data/stubs-t1.txt` came back EMPTY on the first run
that linked. With `main` empty, every tier-1 symbol is unreachable and the whole
module is dead-code-eliminated, so the stub list reads "nothing missing" when it
has in fact measured nothing. `docs/audit/README.md` predicted exactly this
failure mode and is why `gen_manifest.php` emits a smoke entry at all — the
smoke entry cannot do its job while this bug drops it.

**A tier result is only trustworthy once the smoke binary prints its line.**

## Not a regression from the guard-folding change

Verified with a condition that cannot fold (`strlen("abc") > 0`): same silent
result. Pre-existing.

## Shape of the fix (not attempted)

`return` inside an included file means "stop THIS file", which a flat `__main`
cannot express. Closing it wants each non-entry file's top-level statements in
their own scope — the lazy per-file unit model already recorded under
`include-once-repeat-not-tracked`, which changes WHEN every vendor file's
top-level code runs and wants its own worktree and a full gate.
