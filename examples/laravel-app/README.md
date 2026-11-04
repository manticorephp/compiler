# Laravel application

A Laravel 13 application (HTTP kernel, routing, Eloquent over SQLite, sessions, Blade,
Carbon, Monolog) that Manticore compiles into one native binary with its own HTTP server.
The binary needs neither PHP nor Composer nor `vendor/` at run time; it reads `.env`,
`config/` and `storage/` from the project directory it was built in, so run it from there.

```bash
composer install
cp .env.example .env
php artisan key:generate            # APP_KEY
touch database/database.sqlite
php artisan migrate                 # creates the tables the binary reads and writes
php aot/compile-views.php           # Blade views -> plain functions (see below)
manticore build                     # -O 0 for a faster, slower-running build
PORT=8181 WORKERS=4 ./bin/app
curl localhost:8181/hello           # hello from manticore
curl localhost:8181/api/ping        # {"ok":true,"name":"world","items":[1,2,3]}
```

`php` and `composer` are only the way to fetch sources, create the key and the database,
and precompile the views: `composer: true` in `manticore.json` compiles every package the
lock file records into the binary. Run `php aot/compile-views.php` and `manticore build`
again after you change anything under `app/`, `routes/`, `resources/views/` or
`composer.json`. `.env` and the database are read when the process starts, so editing
them needs a restart only. A full build compiles the whole framework: give it several GB
of RAM and 15 minutes or more; `manticore build -O 0` builds faster and runs slower.

| Variable | Default | Meaning |
|---|---|---|
| `PORT` | `8181` | TCP port on `127.0.0.1` |
| `WORKERS` | `1` | worker processes forked under one supervisor (`00` runs one process without a supervisor, for a debugger) |
| `MANTICORE_FIBER_STACK` | 1 MiB | bytes of stack per request fiber; Laravel's deep call chains want more, e.g. `268435456` ([async](../../docs/async.md)) |

## What is in it

| Route | |
|---|---|
| `GET /`, `GET /hello`, `GET /counter` | Blade view, plain text, a session counter (cookie + `sessions` table) |
| `GET /api/ping`, `POST /api/echo` | JSON, query string, request body |
| `GET /api/db/version`, `GET /api/db/count` | query builder against SQLite |
| `GET/POST /api/notes`, `GET/PATCH/DELETE /api/notes/{id}` | Eloquent model `App\Models\Note` |
| `GET /up` | Laravel's health check |

## What differs from a stock Laravel skeleton

A compiled binary executes no `require`/`include` of a computed path and no `eval` at run
time, so the parts of Laravel that load code from disk are replaced by build-time steps.
Everything below is in this directory:

- `public/index.php` is the program entry. It boots the HTTP kernel **once**, before the
  server starts, and serves each request through `$kernel->handle()` from Manticore's
  `Http\Server`; `php artisan serve` and `php-fpm` are not involved.
- Each worker runs **one request at a time** through the kernel (an `Async\Mutex` around
  `handle()`); the next connection is read while it waits. Laravel keeps per-request state in
  objects every request shares (the matched `Route`, request-scoped container instances), and
  two requests interleaving at an I/O wait freed an array the other still used. php-fpm has the
  same rule; Octane clones that state per request. Concurrency comes from `WORKERS`.
- `aot/compile-views.php` runs under Zend before the build. It takes the Blade compiler's
  output for every view the application can reach (its own and the framework's) and writes
  each as a function `aot_v_<hash>(array $__data): string` under `app/AotViews/`
  (generated, git-ignored). Blade's own engine `include`s a compiled file and `extract()`s the
  data into its scope; neither exists in a binary.
- `app/Aot/AotBladeEngine.php`, registered in `AppServiceProvider::boot()`, renders those
  functions instead. It extends `CompilerEngine`, so code that inspects the engine (the
  debug error page) keeps working.
- `manticore.json` lists `vendor/nesbot/carbon/lazy` and `vendor/laravel/framework/config`
  under `src`. Carbon picks one of two class files with a `require` whose path depends on a
  runtime check, and the framework's own config files are merged by a computed `require`;
  the build follows only constant paths, so those directories have to be compiled in. `manticore analyze` warns about such a `require` (`include.unresolved`);
  a package with the same shape needs its directory listed the same way.

## Adding to it

Routes, controllers, models and migrations are written as in any Laravel application.
After a change: `php artisan migrate` for a schema change (the binary does not run
migrations), `php aot/compile-views.php` for a view change, then `manticore build`.
Artisan commands, queues and the scheduler are not part of the binary: a console is a
separate program, run `php artisan` as usual for those.

## Known issue

About one process start in three dies with a `libmalloc` free-list abort (exit 133, `SIGTRAP`) on its first
Eloquent request; a process that survives it serves normally. A supervisor (`WORKERS=N`) restarts a dead
worker, so a deployment recovers, but the request that hit it is lost. Tracked in
[#568](https://github.com/manticorephp/compiler/issues/568) with what has been ruled out.

## Troubleshooting

- **`Target class [config] does not exist`, `Call to undefined method …` right after
  boot.** A class the framework loads by a computed path is missing from the build; see the
  `src` entry for Carbon above and the `include.unresolved` warnings in `manticore analyze`.
- **`undefined-function traps (N)` in the build log** lists PHP functions the program
  calls that Manticore does not provide. A trap only fires if the call runs.
- **A crash in the allocator (`mfm_alloc`, `memory corruption of free block`).** A write
  past or after a block, far from its cause. Build with `MANTICORE_ASAN=1` (see `AGENTS.md`
  in the repository root) before anything else.
