# Class drop gives back its element refs — `MANTICORE_RC_PROP_DROP`

Closes the OPEN link of `docs/status/HANDOFF-2026-09-02.md` §4.

## The arithmetic

A buffer's elements carry ONE base ref plus one per retain, against retains+1
releases. Every release returning EXACTLY one is therefore balanced. The local
that built the array already returns one (`_ownel_`, landed earlier); the class
drop did not.

Measured on `/tmp/mc/elem3.php` (`$tmp = []; $tmp[] = new Tok; $h->els = $tmp;`
in a 20 000-round loop), gate OFF:

```
array_retain_obj      = 20000   property store, co-owns both elements
array_release_own_obj = 20000   the local's release — returns one          ✓
array_release_obj     = 20000   the holder's drop — buffer 2 -> 1, returns NOTHING
rc_release            = 60000   1 holder + 2 elements, one ref each
tagged_alloc 60000 / tagged_reclaim 20000
```

Two references took element refs, one gave one back. Both elements park at rc 1
forever.

## The fix

`EmitLlvm::classDropFlavor()` — `discardReleaseFlavor` plus the `own` suffix
when `$propOwnElem` (the module-wide store scan that already serves
release-before-overwrite) proves every store to that slot hands it element refs
at exactly the drop's depth. `dropHelperFor()` learns the three `_ownel_`
symbols. Wired into `EmitLlvmRuntime::dropRuntimeBody` and the cycle
collector's `__cc_dropscalar_` path — the same slot reference, so the same
accounting.

## ⚠ The ODR condition, which is why this is not a one-line change

`__mir_drop_<id>` is `linkonce_odr` and coalesces BY NAME across every object
file that emits the class, while `$propOwnElem` is MODULE-LOCAL. A program
module that proves a slot would silently replace the library's conservative
body for the same class — and the library's own stores, which the program
cannot see, may hand the slot no element refs at all. That is an over-release
on a live buffer.

So three exclusions, all soundness and not taste:
  - a LIBRARY module (`$propBorrowUnknown`) never proves a slot;
  - an IMPORTED class (`isExternClass`) never takes the `own` flavor;
  - a PRELUDE class (`isPreludeClass`) likewise.

Part splitting is NOT affected: `Main.php:626` splits the emitted IR TEXT, so a
45-part build still runs exactly one whole-program scan.

## Result — `tools/prof/rcbalance.php`, 20 000 iterations

| shape | gate OFF | gate ON |
|---|---:|---:|
| `prop_from_local` | 60001 / 20001 | **60001 / 60001** |
| `prop_elem_hold`  | 60001 / 20001 | **60001 / 60001** |

Every other shape is unmoved, controls included (`prop_from_literal` 60001 /
60001, `prop_ow_read` / `prop_ow_noread` 20002 / 20002, all `ret_*`, both
`append_*`). The whole 14-row table is flat.

AOT suite with `MANTICORE_RC_ELEM_TYPE=1 MANTICORE_RC_PROP_DROP=1`, `-j 0`:
**passed 1026, failed 0, total 1028** — the same rows as the green baseline at
`1348d48`. The element-drop epic predicted 5 reds for a symmetric drop; gated on
the per-slot proof there are none.

## Still owed before the default flips

The gate is OFF by default. It has not run a self-host gen-2, difftest, or
Linux. Those are what the other two ownership fixes cleared before shipping
default-ON, and the failure this guards against — an over-release — is
invisible under Zend, invisible at -O0, and often invisible until gen-2.

---

# Default ON — gen-2 / gen-3 (`31ed18f`)

## Both gates are ONE fix, not two

`MANTICORE_RC_PROP_DROP` alone does NOTHING. Measured on the probe with the
gen-1 binary, which reads both env vars:

| gates | `release_own_obj` | `release_obj` | alloc / reclaim |
|---|---:|---:|---|
| neither | 0 | 40000 | 60000 / 20000 |
| `RC_PROP_DROP` only | **0** | 40000 | 60000 / 20000 |
| `RC_ELEM_TYPE` only | 40000 | 20000 | 60000 / 20000 |
| **both** | **60000** | **0** | **60000 / 60000** |

Without the element-type refinement `arrayRetainFlavor` answers a plain `vec`
for the store, so `markPropOwnElem`'s `retain === drop` test fails and EVERY
slot is vetoed — the `own` flavor is never reached at all. Both defaults were
therefore flipped together; either alone leaves the leak in place.

## The regression test fails without the fix

`tests/aot/cases/prop_elem_drop_symmetry.php` — an array built in a local, then
stored into a property, with `__destruct` on the elements:

```
gate OFF: built 2 / between / built 2 / done          ← all four destructors MISSING
gate ON:  built 2 / drop 1 / drop 2 / between / …     ← byte-identical to php
```

## Generations

```
gen-1  the pre-flip binary (defaults OFF)
gen-2  built by gen-1 with both gates forced on
gen-3  built by gen-2 on its own defaults
```

**gen-2 and gen-3 are BYTE-IDENTICAL** — binary and `lib/manticore_stdlib.o`
both. The fixpoint is reached at gen-2, because gen-1-with-env and gen-2's own
defaults emit the same codegen. gen-3 also reproduces the opt-out
(`MANTICORE_RC_PROP_DROP=0` still drops the four destructors).

AOT suite at gen-3, on defaults, no env: **passed 1027, failed 0, total 1029**
(+1 case = the new regression test).

## It reaches the slot the epic is about

`MANTICORE_DROP_TRACE=1 bin/build` — 566 `CLASSDROP` verdicts in the compiler's
own build, including the two that matter:

```
CLASSDROP Lexer\Lexer::tokens    YES vecobjown
CLASSDROP Parser\Parser::tokens  YES vecobjown
```

⚠ The first attempt at this measurement read **zero**. `bin/build` runs pass 1
as `bin/manticore build … 2>&1 | tee`, so the app pass's stderr is folded into
bin/build's STDOUT — a `2>log` captures only pass 2, which is the LIBRARY, and a
library module vetoes every slot by design. Redirect BOTH streams.

## What it is worth

Self-build (the compiler compiling `src/`), idle machine, one run each:

| | gen-1 | gen-3 |
|---|---:|---:|
| peak RSS | 2 356 MB | **2 321 MB** (−1.5%) |
| user CPU | 66.13 s | 66.49 s |

Profile-instrumented compilers built by each generation, one `dump-mir`:

| | gen-1 | gen-3 |
|---|---:|---:|
| `array_release_obj` (class drops) | 2 479 | **64** |
| `rc_release` | 4 026 943 | **4 242 020** |
| `rc_retain` | 5 201 067 | 5 200 858 |

**+215 077 references handed back for the same number of retains.** That is the
leak being repaid, directly.

⚠ `tagged_reclaim` is 691 in BOTH against `tagged_alloc` 257 072 — a single
`dump-mir` holds its whole AST and MIR live to exit, so this workload cannot
show a reclaim delta no matter what is fixed. Read the RELEASE counters here;
the reclaim number needs a many-file tier where objects actually die.

**Still owed:** the T6 / Doctrine tier A/B (`tools/prof/tier.sh 6`, `BIN=` per
generation) is the only workload that can price this in bytes; difftest and
Linux are unrun for this branch.
