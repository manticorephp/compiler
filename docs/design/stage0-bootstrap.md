# Stage0 bootstrap — building the compiler from a pinned release

**Status:** shipped (#105: PR #112 pin, PR #114 seed removal), 2026-10. The procedure contributors
follow is the "Bootstrap: the pinned release" section of `AGENTS.md`. This note explains why the
design looks the way it does.

## The problem

The compiler is written in the language it compiles, so building it requires an existing
compiler. Until 0.13 the last-resort compiler was the Zend interpreter: `bin/compile` ran
`src/` under php (`tools/compile_files_mir.php`) to produce a throwaway seed, and the seed built the
real binary. That had two costs:

- **`src/` was frozen at what Zend runs.** The compiler could not use its own superset features
  (`Manticore\Ds` containers, `#[TypeDef]` element types, inline generics) in the code that would
  gain most from them: symbol tables, id sets, node maps.
- **The fallback hid mistakes.** CI fell through to the seed whenever the warm compiler could not
  build the tree. A merge that both added a feature and used it in `src/` stayed green, and the
  cost only showed up for the next person without a cache.

## The model

The same approach as Go (`go1.N` builds with `go1.N-2`) and Rust (`src/stage0`): the compiler
that builds the compiler is a named, published release.

```
BOOTSTRAP_VERSION ──► tools/fetch_bootstrap.sh ──► stage0 (release N)
                                                     │ bin/build pass 1+2
                                                     ▼
                                                   gen1 (this tree, built by N)
                                                     │ bin/build again
                                                     ▼
                                                   gen2 (this tree, built by itself)  ← what ships / is tested
```

- **`BOOTSTRAP_VERSION`** (repo root, one line) names the oldest release that can build the tree.
  Every consumer reads it: `bin/build`, `tools/docker/gate.sh` (CI and the release), the Dockerfiles'
  `build` stage, and `install.sh`. There is no second copy.
- **`tools/fetch_bootstrap.sh <dest>`** downloads `manticore-<v>-<os>-<arch>.tar.gz` from release
  `v<v>`. It detects glibc vs musl the same way `install.sh` does and checks the tarball against
  the release's `SHA256SUMS`. A missing checksum is a hard error, because this binary builds every
  later compiler. It also checks that `manticore version` runs and prints that version. Needs curl
  or wget; no php.
- **Two generations, always.** gen1 is the tree compiled by an older compiler, so it carries every
  miscompile that compiler had. Only gen2 carries the tree's own fixes in its code. `bin/build`
  re-runs itself once after a stage0 fetch, and `gate.sh` always builds twice. (A v0.11.0 seed once
  built a compiler that SIGSEGV'd on every http program on amd64, while the same tree rebuilt by
  itself was clean.)

### Where each consumer gets a compiler

| Consumer | Order of sources |
|---|---|
| `bin/build`, local | `bin/manticore` → fetch the pin |
| CI suite rows (`gate.sh`) | runner cache → published `main-<tag>` image → pin |
| CI `bootstrap-from-pin` | pin only |
| `release.yml` | pin only (Linux via `gate.sh`, macOS via `bin/build`) |
| `Dockerfile` `build` stage | pin (`bin/build`) |
| `install.sh` | published tarball → installed compiler rebuilds → pin |

Each source that restores gets a full two-generation build. If it cannot build the tree, the next
source is tried. Nothing falls back to Zend; when every source fails, `gate.sh` stops with a
"bootstrap gap" result that names the pin.

## The two-step rule

The cache and the published `main` image are always newer than the pin, so a suite row stays green
over a merge the pin cannot build. That is why `bootstrap-from-pin` exists: it builds from the pin
alone, and it is a required check on `main`, together with the five suite rows. The rule it
enforces:

1. Land the feature (compiler, runtime, stdlib) without using it in `src/`. Release N on every
   platform in the matrix (linux and linux-musl × arm64/amd64, macos-arm64): `fetch_bootstrap.sh`
   fails on a platform with no tarball.
2. Raise `BOOTSTRAP_VERSION` to N in its own commit; `bootstrap-from-pin` must be green.
3. Use the feature in `src/`.

### What needs a raise and what does not

| Change | Raise the pin? | Why |
|---|---|---|
| Memory-ABI bump (layout, tags, rc encoding) | **no** | The old compiler compiles the new source with its *own* runtime inside the compiler (`"stdlib": false`), and pass 2 rebuilds `lib/*.o` with the new binary, so no program mixes two layouts. Proven on v8→v9; later bumps up to v18 went through a plain `bin/build`. |
| New codegen builtin with a same-named PHP body | **no** | The old compiler links the PHP body and the new one inlines the builtin (`AGENTS.md`, BOOTSTRAP RULE). |
| New stdlib / prelude PHP | **no** | The old compiler compiles it from the tree like any other source. |
| New syntax used in `src/` | **yes** | The old parser cannot read it. |
| A builtin used in `src/` *without* a PHP body | **yes** | The old compiler turns the call into an undefined-function trap; `bin/build` treats any trap in `src/` as a bootstrap gap. |
| A superset feature used in `src/` (`Manticore\Ds`, `#[TypeDef]` semantics, generics) | **yes**, once the old compiler cannot express it | Same reason. |
| A compiler fix the new `src/` relies on to compile correctly | **yes** | gen1 would be miscompiled. |

`bin/build` finds most gaps before it swaps anything in. A preflight (`manticore analyze src --only
undefined.,parse.error`, about 2 s) catches unknown names and syntax. The build log is then scanned
for "undefined-function traps", and the new binary must compile and run hello world. The error
names both the installed version and the pin.

## Why not keep the Zend seed as well

It was kept briefly as an opt-in after #112 and then removed (#114). As long as it existed, `src/`
had to stay runnable under php for no benefit. php's only remaining roles are the difftest oracle
and a few Zend-hosted dev tools (`tools/compile_user_mir.php`), and those keep working only while
`src/` happens to stay php-compatible.

## Risks and how they are handled

- **The release chain is now critical infrastructure.** A withdrawn or broken release blocks every
  build without a cache. Mitigations: the pin names one exact version whose tarballs are
  checksummed; `bin/.manticore.prev` and any installed compiler still work as local builders;
  `MANTICORE_GITHUB=<owner/repo>` points `fetch_bootstrap.sh` at a mirror.
- **Rebuilding from source alone means walking the release chain.** This is accepted, as in Go and
  Rust. From 0.14 on, each release is built by the pin recorded in its own tree, so the chain
  can be replayed tag by tag.
- **A stale pin is not a problem in itself.** Raise it only when `src/` needs something newer.
  Raising it more often shortens the chain, but buys nothing on its own.

## Files

- `BOOTSTRAP_VERSION`, `tools/fetch_bootstrap.sh`
- `bin/build`: the stage0 fetch, the second generation, and the gap diagnosis.
- `tools/docker/gate.sh`: `restore_bootstrap` and the source loop.
- `.github/workflows/ci.yml`: the `bootstrap-from-pin` job.
- `.github/workflows/release.yml`: the release builds from the pin.
