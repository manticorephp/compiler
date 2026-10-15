# Symfony application

A Symfony framework application (`symfony/framework-bundle`, routing attributes, the
service container, HTTP kernel) that Manticore compiles into one native binary with its
own HTTP server. The binary needs neither PHP, nor Composer, nor `vendor/` at run time; it
reads `config/` and `.env` from the project directory it was built in, so run it from there.

```bash
composer install
manticore build
PORT=8181 WORKERS=4 ./bin/app
curl localhost:8181/        # {"message":"Hello, World!"}
```

`composer install` is only the way to fetch the sources: `composer: true` in
`manticore.json` compiles every package the lock file records into the binary. Run
`manticore build` again after you change anything under `src/` or `composer.json`;
`config/*.yaml` and `.env` are read when the process starts, so editing them needs a
restart only. Commit `composer.lock` in a real application to pin the package versions.

| Variable | Default | Meaning |
|---|---|---|
| `PORT` | `8181` | TCP port on `127.0.0.1` |
| `WORKERS` | `1` | worker processes forked under one supervisor |
| `APP_ENV` | `prod` (from `.env`) | Symfony environment; `dev` turns the debug mode on |

## What differs from a stock Symfony skeleton

A compiled binary executes no `require`/`include` and no `eval` at run time, so the
parts of Symfony that load code or write a compiled container to disk are replaced by
their in-memory equivalents. Everything below is in this directory:

- `public/index.php` is the program entry. It boots the kernel **once**, before the
  server starts, and then serves each request through `$kernel->handle()` from
  `Manticore`'s `Http\Server`. It loads `.env` itself, because the Symfony Runtime
  component does not run.
- `src/Kernel.php` registers its bundles statically (`registerBundles()`) instead of
  `require`-ing `config/bundles.php`, and keeps the compiled container in memory
  (`initializeContainer()`) instead of dumping a PHP class to `var/cache` and including it.
- `src/LogReset.php` drops the log records the fallback logger keeps; a long-running
  worker has no process boundary to do that for it.
- `compat/InstalledVersions.php` stands in for `Composer\InstalledVersions`, whose data
  file does not exist in a binary (`extensions.compat` in `manticore.json`).
- `composer.json` lists the `symfony/polyfill-*` packages under `replace`: Manticore
  provides those functions itself.

## Adding to it

Controllers live in `src/Controller` and are routed by the `#[Route]` attribute, exactly
as in Symfony. Services are autowired from `src/` (`config/services.yaml`). Another
bundle goes into both `composer.json` and `Kernel::registerBundles()`.

There is no `bin/console` here: a console is a separate program, see
`examples/symfony-console`.

## Troubleshooting

- **The first build is slow and large.** Symfony is compiled whole; give the build a few
  GB of RAM and a few minutes.
- **`undefined-function traps (N)` in the build log** lists PHP functions the program
  mentions but Manticore does not implement (for example `apcu_*`, `igbinary_*`, `eval`).
  They are only reached by optional code paths; a request that hits one stops with
  `Call to undefined function`.
- **Debugging a crash:** build with `MANTICORE_FRAME_POINTERS=1 manticore build` and run
  the binary under `lldb` (macOS) or `gdb` (Linux) to get a usable backtrace.
