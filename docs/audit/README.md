# symfony-demo gap audit

An audit, not an epic: it **enumerates and ranks, and fixes nothing**. The output
replaces guesswork about what a symfony web app needs with a list where every
row names a file that backs it. Two follow-on epics take their input from here —
the SAPI layer and pdo_sqlite.

Read [`GAPS.md`](GAPS.md) first. It is generated; `php tools/audit/rank.php --check`
fails if it drifts from its inputs.

## Corpus, pinned

| | |
|---|---|
| app | symfony-demo, `/Users/taras/var/projects/symfony-demo-probe/app` |
| symfony | v8.1.0 (`env: prod, debug: false`) |
| packages | 99 (`composer.lock` `packages`, `--no-dev`) |
| `composer.lock` sha1 | `5ac968d9f6af9dbb89fa6a3b5f81b905df3a3773` |
| oracle | `php` 8.5.8 |
| compiler | this worktree, branch `symfony-audit`, off `main` @ `f08a98b` |

The whole gap list is only reproducible against that one lockfile. The probe app
lives in a sibling directory so vendor code never enters this repo.

`php bin/console cache:warmup` was run **under the oracle** before measuring.
That is a deliberate cheat and a good one: it turns the dumped DI container, the
compiled Twig templates and the routing matcher into ordinary static PHP files
on disk, which is exactly what whole-program AOT wants, and it takes runtime
YAML parsing and attribute reflection off the critical path. It does **not**
remove the `include`-returns-a-value gap — the dumped container still `include`s
its lazy-service files.

⚠ symfony-demo's `config/bundles.php` names dev-only bundles that `--no-dev`
does not install, so `APP_ENV=prod` is required for the oracle to boot at all.

## The ladder

A tier's dependency closure lies entirely in lower tiers. That is what makes a
finding at tier N attributable to tier N — nothing above it is in the analyzed
closed world — and it makes the ladder double as the implementation order.

Tiers are declared by **anchor package only**; membership is computed from the
`composer.lock` require graph by `tools/audit/gen_tiers.php`, so the closure
property holds by construction instead of by hand. All 99 packages are assigned;
the generator exits non-zero if any is not.

| tier | anchors | what it isolates |
|---|---|---|
| T1 | psr/\*, contracts, polyfills | zero-dependency. **Settled the mbstring question early**: symfony's polyfills declare `mb_*`, `grapheme_*` and `normalizer_*` themselves, so native mbstring is not on the critical path |
| T2 | string, var-exporter, finder, yaml, process | heavy string and array work; where element-repr lands |
| T3 | config, dependency-injection, event-dispatcher, http-foundation | first tier touching superglobals and heavy reflection. **Produces the SAPI requirement list** |
| T4 | routing, http-kernel, console, framework-bundle | the kernel; `include` bites here. `symfony/console` is already byte-exact, so it is the **control** |
| T5 | twig, translation, intl, asset | templates, already compiled by the warmup |
| T6 | doctrine/\* | **produces the pdo_sqlite requirement list** |
| T7 | security, form, validator, mailer, ux | leaves |
| T8 | the application itself | last, so app-specific gaps never mask framework ones |

## Three lanes, and what each is blind to

**(a) static — `manticore analyze`.** Ran over all eight tiers, cumulative closed
world, no crashes. Sees undefined symbols, arg count/type, return type, and —
the highest-confidence output of the whole audit — `parse.error`, which needs no
interpretation at all.

Raw counts are not findings. A tier's world deliberately stops at tier N, so
every reference up the ladder reads as undefined; at T2 that artifact outnumbers
real findings ten to one. `tools/audit/triage.php` splits the output four ways:
parse error, out-of-scope (declared in a higher tier), blind spot (declared in
this tier and the analyzer missed it), absent (declared nowhere).

*Cannot see*: anything semantic — that is what the probe suite is for — anything
behind a dynamic call, and anything at all about runtime correctness. **A clean
analyze is not "it works".**

**(b) build + stubs — NOT YET RUN.** `tools/link_stubs.sh` writes a
`void* sym(){return 0;}` for every unresolved symbol, and `STUBS_PREFIX` makes
that file's path deterministic, so it is a machine-readable list of what the
live, post-dead-code-elimination program cannot resolve. `run_tier.sh` drives it
and `demangle_stubs.php` filters the by-design `manticore_rt_*` family out.

⚠ It must be a **manifest** build: `manticore compile` links with a plain `cc`
and hard-fails on a missing symbol; only the manifest path calls `link_stubs.sh`.

*Cannot see*: everything that links — which includes **every** bare-alias
capture, the highest-severity finding class in this audit — and everything in
dead code.

**(c) runtime vs the `php` oracle — NOT YET RUN.** Two sub-lanes, neither
needing a SAPI: `bin/console` (`list`, `about`, `debug:router`,
`debug:container`, `lint:twig`) and a `Kernel::handle(Request::create(...))`
harness. `Request::create()` reads no superglobals, so the entire web stack is
measurable against a CLI oracle.

*Cannot reach, ever*: `Request::createFromGlobals()`, real `header()`/`setcookie()`
emission, a `session_*` round-trip, `ob_*` around a rendered response, and
`$_FILES` uploads. Those five are the SAPI epic's acceptance criteria; the audit
records them by static reference instead, in
[`SAPI-REQUIREMENTS.md`](SAPI-REQUIREMENTS.md).

## Completeness bound, stated

- Lanes (b) and (c) have **not** been run. Everything currently in `GAPS.md`
  comes from lane (a) plus the capability probe suite. Lane (b) can only add
  rows; lane (c) can add rows and can promote an S2 to an S0.
- Linux has **not** been run. macOS green proves nothing, and a tier whose
  finding set differs across platforms is itself an S0/S1.
- `--deep` (the MIR type passes) has not been run per tier.
- The audit measures the tree at `main` @ `f08a98b`, with the three analyzer
  calibration fixes on top. It says nothing about un-merged branches.

## Calibration — why the numbers can be trusted at all

The analyzer is the audit engine, so it had to stop lying first. On
`symfony/console`, which compiles **byte-exact**, it reported 236 undefined
functions — every one a false positive by construction. Five roots, all
mechanical, four fixed:

1. `analyze_prelude_files()` had drifted four files behind `prelude/`.
2. `analyze_stdlib_fn_names()` dropped every namespaced `.sig` decl, so it never
   learned the bare-name alias rule the lowering actually applies.
3. `Analyze\Builtins` had drifted 51 names behind the `emitBuiltin` dispatch,
   `end`/`reset`/`current`/`key`/`next`/`prev` among them.
4. Neither `Index` nor `Decls` descended into a conditional block, so the entire
   polyfill idiom — `if (!function_exists(X)) { function X(){} }` — was
   invisible. 30 distinct names. The rule now lives once, in `Analyze\DeclScopes`.
5. `use function Ns\name` imports are still unmodelled — 10 sites, recorded in
   `data/calibration-residue.txt` and classed S4 rather than chased.

Root 2 is load-bearing twice: modelling the alias rule also **enumerates** it,
which is where the audit's top two findings come from.

`tools/audit/calibrate.sh` gates both directions and runs at the start and end
of every phase. Note its bar is not "zero undefined": a green corpus legitimately
names symbols it does not ship, and manticore legitimately lacks functions the
corpus never calls on a live path. Only **blind spots** — a symbol declared in
the corpus that the analyzer could not see — make the audit lie, and those must
be zero.

## Regenerating everything

```bash
bash tools/audit/calibrate.sh                                   # gate first
php  tools/audit/alias_scan.php                                 # -> data/alias-capture.tsv
php  tools/audit/cap_run.php --regen                            # -> data/capability.tsv
php  tools/audit/gen_tiers.php <app>                            # -> tiers.json
for t in 1 2 3 4 5 6 7 8; do bash tools/audit/run_tier.sh $t; done
php  tools/audit/triage.php 8                                   # findings, per tier
php  tools/audit/requirements.php                               # -> the two requirement docs
php  tools/audit/rank.php                                       # -> GAPS.md
php  tools/audit/rank.php --check                               # proves GAPS.md is not hand-written
```

A pipe eats the exit code, so every one of these is meant to be run as
`cmd >/tmp/x 2>&1; echo "rc=$?"` when the status matters.

## The probe suite

25 capability probes, one Zend behaviour each, run under `php` and under
manticore and diffed. They live in two places **by their current status**, and
that placement is load-bearing:

- `tests/aot/cases/cap_*.php` — probes that pass today. Auto-discovered by the
  suite, so they gate forever.
- `docs/audit/probes/cap_*.php` — probes that fail today. A permanently-red case
  in `tests/aot/cases` would break the gate and get muted, which is exactly how
  a known gap turns into an unknown one.

**Promotion rule, for the epics that follow:** whichever epic closes a gap moves
its probe from `docs/audit/probes/` into `tests/aot/cases/` in the same commit.
The audit is meant to self-liquidate.
