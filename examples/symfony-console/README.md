# Symfony Console

A minimal Composer application that Manticore compiles together with
`symfony/console`. The Composer dependency is source at build time; the native binary
does not need PHP, Composer, or `vendor/` when it runs.

```bash
composer install
manticore build
./bin/demo greet Ada
./bin/demo --help
```

`composer install` creates `composer.lock` on the first run when it is absent. Commit
that lock file in a real application to make dependency resolution reproducible.

The `composer: true` field in `manticore.json` is the important part: it includes the
project's Composer autoload roots and every package recorded in `composer.lock`.

## Undeclared names (`allowUndefined`)

`manticore build --check` reports a reachable use of an optional package or extension this app does
not install as an error. `manticore.json` lists them under `allowUndefined` so the build passes: the Process component (\`completion --debug\`) and the event-dispatcher contracts.
Each path throws `Error` at run time if it runs. A new missing dependency not in the list still fails
the build. See [`docs/cli.md`](../../docs/cli.md#allowundefined).
