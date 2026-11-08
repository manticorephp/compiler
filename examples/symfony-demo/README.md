# symfony/demo

The [Symfony Demo application](https://github.com/symfony/demo) — Symfony 8.1, Doctrine
ORM 3.6 over SQLite, Twig, Symfony UX (Twig/Live components, Stimulus, icons), security,
translations in 30 locales — compiled by Manticore into one native binary with its own
HTTP server. No php runs when the binary serves a page.

This directory holds only what adapts the application to Manticore. `setup.sh` fetches
the rest from upstream at a pinned commit.

## Status

Every page below, requested one after another from a single long-lived process, is
byte-identical to what php 8.5 renders from the same tree (render timestamps and the
port aside):

| Page | |
|---|---|
| `/` | home |
| `/en/blog/`, `/en/blog/page/2`, `/uk/blog/` | post list, pagination, another locale |
| `/en/blog/posts/{slug}` | a post with its comments (Doctrine lazy entities) |
| `/en/blog/rss.xml` | feed |
| `/en/login` | login form |
| `/en/admin/post/` | `302` to the login form when signed out |

`/en/blog/search` renders natively; php itself fails on that page under the harness used
for the comparison, so it has no reference yet. Checked on macOS arm64; Linux is the
target platform but has not run this example yet.

## Requirements

- **Manticore built from this repository** (`bin/build` at the repository root). The
  example needs compiler features that are newer than any release.
- **php 8.4+ CLI** with `intl`, `pdo_sqlite`, `mbstring`, `ctype`, `iconv`, `dom`,
  `tokenizer` — for the build-time steps only (below), never at run time.
- **Composer 2**, **git**, **rsync**, and network access for the first `setup.sh`.
- **Several GB of free RAM and about 20 minutes** for the build: the application compiles
  to ~79k functions. The `-O 0` binary is ~900 MB.

## Build and run

```bash
cd examples/symfony-demo
./setup.sh
../../bin/manticore build -O 0 manticore.json
APP_SECRET=change-me MANTICORE_FIBER_STACK=8388608 PORT=8000 ./bin/app
open http://127.0.0.1:8000/
```

Run the binary from this directory: it reads `config/`, `.env`, `data/database.sqlite`
and serves `public/` from the directory it was built in. Sign in with the demo accounts
from upstream's README (`jane_admin` / `kitten`).

| Variable | Default | Meaning |
|---|---|---|
| `APP_SECRET` | — | **required**, any non-empty string (Symfony's CSRF and remember-me secret) |
| `MANTICORE_FIBER_STACK` | 1 MiB | stack of the fiber each request runs in; Symfony and Twig at `-O 0` need **8 MiB** (`8388608`), less overflows the stack on the deeper pages |
| `PORT` | `8181` | TCP port on `127.0.0.1` |
| `WORKERS` | `1` | worker processes forked under a supervisor; `0` serves in this one process (use it under a debugger) |
| `APP_ENV` | `prod` | the only environment set up here: `--no-dev` leaves out the profiler and the debug bundles |
| `DEMO_RAISE` | — | `1` lets an exception escape the kernel to the server's error log instead of rendering Symfony's error page |

## Why the build-time steps run under php

A compiled binary runs no `eval` and no `include` of a file that was not compiled in. The
parts of Symfony that generate PHP at run time therefore run once, under php, before
`manticore build`, and their output is compiled into the binary (`src` in
`manticore.json`):

- **Twig templates** (`bin/warm-twig.php` → `var/cache/prod/twig`). Twig turns each
  template into a PHP class on first use; the binary carries all of them.
- **Translation catalogues** (same script → `var/cache/prod/translations`). Symfony caches
  each catalogue as a PHP file that it `require`s.
- **Asset map** (`bin/warm-assets.php` → `public/assets`), after `importmap:install` and
  `sass:build`. The binary serves the compiled assets as static files.

So **after changing a template, a translation or an asset, run `./setup.sh` again and
rebuild**. Changes to `config/*.yaml` and `.env` need a restart only: the container is
built when the process starts.

## What differs from upstream

- `public/index.php` is the program entry: it loads `.env` itself (the Symfony Runtime
  component does not run), boots the kernel **once** before the server starts, and serves
  every request through `$kernel->handle()` from Manticore's `Http\Server`, including the
  static files under `public/`.
- `src/Kernel.php` registers its bundles in code instead of `require`-ing
  `config/bundles.php` (which `setup.sh` removes), keeps the compiled container in memory
  instead of dumping it to `var/cache`, and builds lazy *services* eagerly:
  `ReflectionClass::newLazyProxy` is not supported.
- `manticore.json` compiles `src/`, the packages of `composer.lock`, the bundles' PHP
  config files and the warmed caches. `"lazy": ["App\\Entity\\"]` names the classes
  Doctrine may create as lazy ghosts (`ReflectionClass::newLazyGhost`): only those pay
  for the check on every property read.
- `compat/` stands in for two things a binary cannot load: `Composer\InstalledVersions`
  (its data file) and `symfony/polyfill-deepclone` (`Closure::bind` over private state).
- `.env` sets `APP_ENV=prod`.

## Troubleshooting

- **A page crashes the process** (`exit 139`): check `MANTICORE_FIBER_STACK` first.
- **A page renders an old template or misses a translation**: the warmed caches are
  compiled in; rerun `./setup.sh` and rebuild.
- **`newLazyGhost`: "is not lazy in this build"**: the class is missing from `"lazy"` in
  `manticore.json`.
- **The build was interrupted**: an interrupted build leaves `bin/app` unchanged; just run
  it again.
