# Profile-guided optimisation

A Manticore binary can be rebuilt with a profile of how it actually runs: clang
then inlines the hot calls, lays the hot path out straight, moves the cold
branches (errors, rare types) out of the way and predicts branches from real
counts. The output is the same program; only its machine code changes.

Measured on 2026-11-16 (macOS arm64):

| program | without | with PGO |
|---|---|---|
| the compiler itself, symfony-app build (`-O0`, warm) | 68.4 s CPU | 58.6 s (−15%) |
| the compiler itself, its own `-O0` self-build | 47.1 s CPU | 40.3 s (−15%) |
| `serialize()` of 200 objects x200 | 18.2 ms | 12.9 ms (−29%) |
| `unserialize()` of the same | 104.7 ms | 94.1 ms (−10%) |
| a JSON "hello" endpoint under `wrk` | ~13.2k req/s | within noise (network-bound) |

The gain is in CPU-bound code. A request that spends its time in the kernel and
the network stack has little for PGO to improve.

## The compiler

```bash
bin/build --pgo
```

Instruments the compiler, trains it on its own self-build, rebuilds it with the
profile and smoke-tests the result (about four more minutes). Needs
`llvm-profdata` matching the host clang (Xcode ships it; on Debian it is in
`llvm-NN`). Release builds use it.

## A program of your own

```bash
MANTICORE_PGO_GEN=1 manticore build manticore.json          # instrumented binary
LLVM_PROFILE_FILE=/tmp/app-%p.profraw ./bin/app …            # a REPRESENTATIVE run
llvm-profdata merge -o app.profdata /tmp/app-*.profraw      # (macOS: xcrun llvm-profdata)
MANTICORE_PGO_USE=app.profdata manticore build manticore.json
```

`manticore compile file.php` takes the same two variables.

- **Train on what production does.** clang optimises the paths the profile saw;
  a profile of the wrong workload moves the wrong code out of the way.
- **The profile is written at a normal exit.** A server must be stopped with
  `SIGTERM` or `SIGINT` (the built-in HTTP server shuts down cleanly on both), not
  `SIGKILL`. Every forked worker writes its own file (`%p`); merge them all.
- **A stale profile is harmless.** After the code changes, functions the profile
  does not match are compiled as without it; retrain to get the gain back.
- **The prebuilt stdlib** (`lib/manticore_stdlib.o`) is not rebuilt with your
  profile: the program, the prelude and the runtime helpers inside it are.
- The profile reaches clang under its content hash, so a new profile never
  reuses objects the object cache built with an old one.
