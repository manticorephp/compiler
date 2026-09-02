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
