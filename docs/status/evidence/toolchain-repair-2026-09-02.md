# Toolchain repair — 2026-09-02

Three defects had made the repository unable to build or test itself. All three
landed in the 2026-08-20…28 memory-reduction window and none was ever seen, because
the first one blocked `bin/build` before the other two could be reached.

## 1. `bin/build` refused its own preflight (73 diagnostics)

`src/Runtime/Stdlib/Dns.php` was the ONLY namespaced file in a directory of forty-odd
global-namespace ones. The namespace is deliberate — Zend already defines
`dns_get_record` / `checkdnsrr` / `getmxrr`, so the cold seed cannot parse a global
redeclaration. But the 26 private `__mc_dns_*` / `__mc_resolv_*` wire helpers lived
inside it too, while every call site spelled the GLOBAL name (`\__mc_dns_u16()`), and
`Net.php` — global namespace — called them by that global name as well.

So they resolved for nobody: Zend would fatal on the explicit global call, `Net.php`
could not see them at all, and natively they survived only on the emitter's bare-name
fallback. The closed-world analyzer reported all 73 correctly.

Fixed by splitting: `DnsWire.php` (global, 26 helpers) and `Dns.php` (namespaced, the
4 php builtin names). `analyze src --only undefined.,parse.error` went 73 → **0**, and
a self-host `bin/build` went from refusing to **1 m 17 s**, against 5 m 34 s for the
cold seed it had been forcing.

## 2. Every library interface was empty

`Sig::emitModule` builds a `.sig` by walking `$module->functions`. Emission had been
taught to `unset($module->functions[$key])` as it goes, to drop MIR during codegen —
and the library `.sig` is written AFTER emission. Result:

```json
{"schema":2,"abi":8,"functions":[],"classes":[],"constants":[], …}
```

155 bytes, against 269 KB and 2788 functions before. A stdlib `.o` full of defined
symbols behind an interface that declared none of them, so every program calling one
failed clang with `use of undefined value @manticore_…`.

**531 of 1028 AOT cases were red on this single defect.**

The guard must be `$this->emitLibrary`, not `$module->isLibraryModule`: the latter is
`emitLibrary && exportTypes` (`Main.php:3852`) and is false for the stdlib, so the
obvious spelling left the sig empty and read as "the drain was not the cause".

## 3. Lazy helper bodies were dropped

Emitting a lazily generated helper can register another one. Both drain loops were
`foreach`, which iterates a COPY, so anything registered during the drain was never
written — the call site was emitted, the definition was not. `sapi_ctx_two_tasks` died
on `use of undefined value @manticore___mc_dyn_method0___bits_int_`.

The buffered (`MANTICORE_STREAM_IR=0`) path had lost its drain entirely when the
streaming one was introduced, so with streaming off *every* lazy helper was generated,
registered and discarded.

Both paths now share `drainLazyHelpers()`, which runs to a fixpoint. The registries
double as the generator's dedup table, so a key may not be removed before its body is
written — the body string is blanked and the key kept as a tombstone.

## Result

| | before | after |
|---|---:|---:|
| `analyze src --only undefined.,parse.error` | 73 errors | **0** |
| `bin/build` | refuses (bootstrap gap) | **77 s** |
| `lib/manticore_stdlib.o.sig` | 0 functions | **2788** |
| AOT suite | 495 / 1028 | **1026 / 1028, 0 failed** |

The two non-passing cases at the end are skips; with `MANTICORE_RC_RETURN_OWNS=1` the
suite is `passed: 1026 failed: 0`. Without it exactly one case fails —
`prop_overwrite_destruct_getter`, the regression test for that fix.

⚠ Measurement note: the first post-repair T6 run was taken while `difftest.sh` was
running on the same host. RSS peaks are not comparable across a busy machine (2135 vs
3467 MB has been observed for the same command), so that number is indicative only —
the A/B for the ownership fixes must be run with nothing else on the box.
