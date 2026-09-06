# Handoff — the ownership contract for builtins, and what is left of it

**State**: `f1baf57` merged into local `main`, then `79cd5db` / `2e1b880`
(branch `packown`, merged) and `5ed7347` (branch `arrflavor`). The ownership table is at **LEAK on 0 · parity DIFF 0**.
What is left of the area is one unnamed disagreement, section 3.

Read this before touching rc/ownership code again. It starts from the model, so
a session with none of the context can start at the top.

---

## 1. The model, in one page

A **user function call** carries a complete ownership contract:

- the CALLER releases a fresh rc argument temp after the call
  (`EmitLlvm::freshRcArgFlavor` → `EmitLlvmCalls::emitCall`), and
- the CALLEE retains, on entry, whatever it keeps
  (`EmitLlvmMemory::initRcObjSlots`), and
- a RETURN is `+1`: `EmitLlvmModule::emitReturn` retains a borrowed payload —
  including a borrowed CELL payload — precisely so the caller may drop a result
  it discards.

A **codegen builtin** had none of the three. It reads the argument's buffer
inline and returns, so a fresh temp handed to one had no owner at all. Some
builtins looked correct only by accident: a `boxToCell` rebuild on the way in
frees the source as a side effect (`EmitLlvm::cellifySourceFlavor`), which is
why `implode` and `in_array` measured flat while `count` and `array_values`
leaked. **Accidental correctness is tomorrow's regression** — those names still
need converting even though they measure clean today.

Whether the caller MAY free a builtin's argument is a per-name question with
exactly three answers:

| class | the result … | safe when |
|---|---|---|
| **A** | cannot reference the argument — a scalar, or a string built byte by byte | immediately. `count`, `sizeof` |
| **B** | is a fresh ARRAY built by copying element WORDS with no retain, so it BORROWS the source's elements | the copy CO-OWNS. `array_keys`, `array_values` |
| **C** | IS an element or a key of the argument | the builtin hands back `+1` AND `EmitLlvm::isFreshCellTemp` names it, so a consumer gives that reference back. `max`, `min` over one array |

**The retain and the release are ONE change.** A retain without the matching
release is a leak; a release without the retain is a use-after-free that is
invisible under Zend, invisible at `-O0`, and often invisible until gen-2.
Never land one half.

### Where the machinery lives

- `EmitLlvmBuiltins::emitArrPtrArg(Node)` — emits a builtin's array operand AND
  registers it when it is a fresh owned temp.
- `EmitLlvmBuiltins::emitBuiltin` — a thin wrapper around `emitBuiltinDispatch`
  that marks the pending stack, then releases everything the dispatch
  registered. Nesting works: an inner builtin drains at its own exit.
- `EmitLlvm::builtinMintsOwnedArray(string)` — the NAME list of builtins whose
  ARRAY result is a fresh allocation. Consulted by `freshRcArgFlavor`, because
  `sigs->paramTypes` is evidence a user BODY was called and therefore answers
  "no" for every builtin. Absence is a leak; a wrong entry is a UAF.
- `EmitLlvm::isFreshCellTemp(Node)` — a cell result the caller owns.
- `EmitLlvm::$ptrArgCellByReg` — the cell temp of an `emitPtrArg` operand, keyed
  by the reg `freeStrTemp` will be handed. This is how ~30 string builtins got
  the cell drop with no per-site edit.

---

## 2. What is already done

`tools/ownership_probe.php` is the classifier the first version of this handoff
asked for, and it is now the instrument for the whole area:

    php tools/ownership_probe.php            # the table
    php tools/ownership_probe.php -k array_  # one name
    MANT=… SCALE=4 ITERS=… KEEP=1            # A/B another compiler, wider arm

One probe per builtin — `for (…) $acc += <consumer>(<builtin>(<fresh temp>))` —
RSS at 1x and 2x work (a third point at 4x separates a leak from a pool
plateau), and the same program diffed against `php`. **The ratio flags a MISSING
release, the parity flags an OVER-release**, and it exits non-zero on either, so
it is ready to become the gate Step 2 below still asks for.

The table went **20 leaks → 0**. Every root it found, in the order they fell:

| root | commit | what it was |
|---|---|---|
| a CAST consumed a fresh cell temp with no owner | `a1d7879` | `strlen(json_encode($v))` was flat, `strlen((string)json_encode($v))` leaked the whole document. Three arms in one site: the cell temp had no owner, the cast RESULT had no owner (`isFreshStringTemp` named only int/float/cell operands while the erased dispatch takes the same retain), and the pass-through arm INHERITS its operand — that last one is the whole of `array_pop` / `array_shift`. |
| a string KEY in an array literal escapes | `1b1f3a9` | `function f(int $i): array { return ["k" . $i => …]; }` handed back a map whose every key read as the LAST one written: the key went to the ARENA and `arena_leave` reclaimed it. `KIND_STORE_ELEMENT` had always passed `true` there; `KIND_ARRAY_LIT` had not. **A wrong answer, not a leak.** |
| class C converted | `ea8880a` | `array_first` / `array_last` / `array_key_first` / `array_key_last` + the cursor family. All three halves at once: `cellEndpointRetain`, the operand through `emitArrPtrArg`, the name in `isFreshCellTemp`. `array_pop` / `array_shift` needed none of it — they REMOVE the element, so the reference already transfers. |
| a vec's key is INT keys, not NO keys | `9945898` | `@return string[]` lowers to a VEC over a hashed buffer, and foreach read each string key with the raw packed accessor. THREE gates dropped the disagreement in a row (the ternary join took the then-arm; `Type::unionWith` lifted a null key to STRING where two concrete channels are a CELL; `NarrowReturns` asked `isAssoc()`, which is string-key-only). |
| an ASSOC call result was never owned | `875ef56` | `freshRcArgFlavor` exempted it on a reading of `isBorrowedObjReturn` that had stopped being true. `array_flip` 22 MB, `array_combine` 105 MB. |
| a CLOSURE LITERAL argument | `1d17dde` | `array_filter($t, "strlen")` — lowering coerces the string callable to a closure, `emitClosure` allocates an env at rc 1, and nobody owned it. |
| a `\|` inside brackets is not a top-level union | `a4470e7` | the gate was a plain `strpos`, so `array<int,string\|null>` (str_getcsv, fgetcsv, every `array<K,V\|null>`) collapsed to a bare cell — the `.sig` then carried `mixed`, not `mixed[]`, **and a caller cannot own an erased word**. |
| a variadic pack's elements had no release | `79cd5db` `2e1b880` | The release turns on what the reference TOOK. An OWNED element transferred its +1 — free it by its own flavor. A BORROWED one took a co-owner retain, and giving those element refs back miscompiles the compiler. Section 3. |
| an obj/string ALIAS was retained and never released | `1335a76` `c526192` `f1baf57` | `$s = $x;` leaked one reference per call — the shape half the stdlib opens with. The retain was in the emitter, the release nowhere. Its pass-through half (`(string)$s` is the same pointer, so the same alias) followed, and `Compile\Mir\AliasOwn` is now the ONE predicate both sides read. |

**Gates at `f1baf57`** (merged into local main): suite **1050/1052, failed 0** ·
difftest **MATCH 967 / DIFF 2** (`error_handler_basic`,
`trigger_deprecation_shape`, both pre-existing) · **LINUX arm64, cold seed +
full suite, 1050/1052 failed 0** · the binary is a **bit-for-bit fixpoint**
(gen 0 = gen 3 = gen 4 = gen 5 by SHA-256), which is why `selfhost_fixpoint.sh`
was skipped by choice. Not run: **amd64**.

---

## 3. CLOSED — a pack element's release is what ITS REFERENCE took

`79cd5db` + `2e1b880`. The leak was real and `8ab002a`'s reasoning about it
still holds; what it got wrong is that ONE answer covers both kinds of element.
Table: **LEAK on 0 · parity DIFF 0**; AOT suite **1050/1052, failed 0** at
`12af025` — main's own number. ⛔difftest · fixpoint · LINUX not run.

### The root

A variadic call packs its trailing arguments into ONE array literal
(`LowerFns::defaultFillArgs` → `new ArrayLit($packed, …)`), so `array_merge($a,
$b)` passes `vec[vec[…]]`. A literal OWNS its elements:
`EmitLlvmArrays::emitArrayLitValue` ADOPTS a fresh one and RETAINS a borrowed
one. Its release drops every element kind that has a flavor — `vecstr`,
`vecobj`, `veccell` — but an ARRAY element has none:
`EmitLlvm::discardReleaseFlavor` falls through to a plain `vec`, which is the
repr walk, and a literal stamps no ownership repr. So the buffer was freed and
everything inside it stranded.

### What `8ab002a` assumed, and what it cost

A literal in ARGUMENT position is handed to the callee BY VALUE, so its
elements go with it. There is a standing rule for that shape — a named local in
argument position is marked `FunctionEmitFrame::$elementSharedLocals` by
`EmitLlvm::shareCallArgs` and its scope-exit release becomes
`__mir_array_release_buf`, "the parser `$args` double-free" — and the rule
already vetoes **a variadic tail**. It could never see a pack element, because
it matches on a local's NAME and a pack element is an anonymous temp. It is
also not the whole answer: the veto is right for a BORROWED element and wrong
for an owned one, which is the distinction below.

`8ab002a` released each element with the element's OWN release flavor, which
under `$rcSymElem` is the pairwise-symmetric variant (`__mir_array_release_
ownel_str`): it -1's every string / object in the element as well as the
buffer. Generation TWO then died in `LowerFns::finishClosure` reading
`0xcbf29ce484222325` — the FNV seed of a recycled string header. The producer
was one line away:

```php
if ($k === 'BinaryOp') { return \array_merge($this->collectVars($e->left), $this->collectVars($e->right)); }
```

The element drop freed both packs' strings under the array `array_merge` had
just built out of them.

★★★ **Neither crash site is miscompiled.** `finishClosure`'s IR — and
`bareName`'s, for the second crash — is BYTE-IDENTICAL to main's. What named
the producer was a whole-module per-function IR diff: build the compiler twice
with `bin/manticore build <manifest> --apps-only --keep-ir` (a manifest whose
`output` points outside the repo), normalize `%rN` / `@.str.N` away, hash each
`define` body, and diff the two lists. ~250 functions changed; stripping
`__mir_props_*` and `EmitLlvm__*` left ten, and `LowerFromAst__collectVars` was
one of them.

### The rule, and the half of it that is still dark

**The release turns on what THIS REFERENCE took**, which the retain in
`emitArrayLitValue` has just answered:

- **no retain** — an owned producer (call / literal / spread) transferred its
  +1 and the literal is its SOLE owner. Release it by its own flavor; it goes
  completely. That is `array_merge($a, $b)`: every value in its result comes
  out of the PACK (its `foreach` co-owns the element array and `$out[] = $v`
  retains each value), so the argument's own element refs are the leftovers.
  `array_diff` / `array_intersect` were never in that position — their result
  comes out of `array $arr`, a real by-value parameter the callee retains on
  entry, so buffer-only already balanced them.
- **a retain** — a borrowed alias, co-owned including its elements
  (`arrayRetainFlavor`). Giving those refs back here MISCOMPILES THE COMPILER.
  ⛔**Bisected, not explained.** Both arms dropping (`8ab002a`) died in
  `LowerFns::finishClosure`; the borrowed arm alone, behind a flavor-MATCHED
  retain, died in `LowerFromAst::bareName`; both read a recycled string header.
  Every pair reads symmetric in the IR — e.g. `VivifyRefArgs::vivifyFunction`
  emits two `__mir_array_retain_obj` before the call and two
  `__mir_array_release_ownel_obj` after it — and the retain variant does walk
  its elements. Buffer-only there until the disagreement is named: one leaked
  ref per element per call, the safe direction. **This is the next thing to
  pick up in this area** — `tools/prof/packleak.php alias` is the repro,
  38 → 75 MB at 200k/400k.
- a plain `vec` / `assoc` flavor is the runtime REPR walk, decided by bits a
  literal never stamps — not the reference's answer either, so it degrades to
  buffer-only with them.
### The other half of the hole — the LOCAL, CLOSED

`$x = [explode(",", $s), ["z"]]` in a LOCAL leaked the same way and was not
covered by the list above: `$litElemCollect` is only set while a call ARGUMENT
literal is being emitted, because only there is the by-value hand-off what
justifies buffer-only. A local literal genuinely owns its elements — it just
had no way to say so, because **an ARRAY element had no release flavor**.
`discardReleaseFlavor` fell through to a plain `vec`, the repr walk, over bits
a literal never stamps.

`vecarr` / `assocarr` are that missing member (`5ed7347`), with `'arr'` as a
runtime VALUE flavor whose element drop is `__mir_array_release`:
`release_arr`, `release_ownel_arr`, `retain_arr`, `adopt_arr`. Two rules that
cost a gen-3 abort and 20 red cases:

- ⚠ **The claim does NOT belong in `discardReleaseFlavor`.** That answers for
  PROPERTIES, call arguments and the erased repr path as well, where it
  over-releases. It lives in `rcReleaseFlavorPlain` (the LOCAL SLOT drop, which
  already knows the slot is not `$shared`) and in `arrayRetainFlavor`.
- ⚠ **Both halves or neither.** Landing only the release made
  `array_merge_recursive` answer `[""] => float(2.16E-314)`: the drop walked
  elements the entry retain had never co-owned. `__mir_array_retain_arr`
  emitted ZERO times is how that reads in the IR.

Nested buffers are now flat (32/63 → 1/1 for a literal of literals, 38/75 → 1/1
for call results, 54/106 → 1/1 when overwritten in a loop; `bench/cases/
nested_array_local.php` 42.6/83.2 LEAK → 2.0/2.0 ok). What is LEFT:

- the nested STRINGS (`packleak local` 50/99, `nested` 84/167) — the inner
  array's own release is repr-driven and its producer stamps no repr;
- ✅ **`$q = $r` on a vec-of-arrays** — CLOSED by `00ff78e`, and it was a
  different root: the emitter takes an independent `__mir_array_copy` as soon as
  either side is mutated, so the destination owns a FRESH buffer and the source
  is untouched — while `InsertMemoryOps` read the read-only-ALIAS answer for
  both and blocked each of them (`notowned` / `vecalias`). Neither name got a
  release at all. `Compile\Mir\VecCopyOnAssign` is the one predicate now, the
  way `AliasOwn` is one for obj/string. 69/137 → 1/1; `packleak copy`
  143/284 → 75/148, the rest being the same nested strings.

✅ **The nested STRINGS are closed too** (`a55ac0d`), by the second road: the
OUTER flavor carries the INNER one. `vec[vec[string]]` is `vecarrstr`, whose
walk releases each element as a `vecstr`; `arrobj` / `arrcell` / `arrbuf`
follow, and plain `arr` stays the answer for an inner the type does not know.
`EmitLlvmMemory::nestedArrFlavor` picks it, `EmitLlvm::arrFlavorSuffix` is the
one decoder that release / retain / adopt and the class-drop table all read, and
the runtime gains 16 symbols from two four-name loops. The inner RETAIN is a
plain BUFFER retain — the element's own elements are its own, given back once at
its rc → 0.

Two gates had to move with it, and each was a crash first:

- ⚠ `EmitLlvm::shareCallArgs` knew only obj and string, so a `vec[vec[string]]`
  handed to a callee was never marked element-shared and both sides dropped the
  inner strings.
- ⚠ `InsertMemoryOps::rcSlotFlavor` answered `arr` for every array, so two
  stores that disagree about the INNER element picked different helpers for one
  slot and first-write-wins handed the loser's buffer to the winner's walk:
  `$g = [row($i), ['s']]` then `$g = [[$i], [$i+1]]` released a vec of INTS
  through `__mir_array_release_ownel_arrstr`, reading each int as a string
  pointer. It now names the inner kind so the existing flavor gate blocks the
  disagreement — a leak, never a free of a tag. `nested_array_element_drop` is
  the case that caught it.

    nested string arrays in a local  112/222 → 1/1
    one nested string array           57/112 → 1/1
    packleak local                    50/99  → 1/1
    packleak copy                     75/148 → 1/1

Depth THREE followed: `nestedArrFlavor` asks itself one level down, so
`vec[vec[vec[string]]]` is `vecarrarrstr`, `emitDropValue` reads `arr<rest>` as
"release each element as `<rest>`" at any depth, and
`UnifiedArrayRuntime::nestedFlavors()` emits three levels from one loop —
`PruneIr` drops the ones nobody reaches, so the binary grew 112 bytes.
`InsertMemoryOps::rcSlotFlavor` walks the WHOLE chain for the same reason it
named one level. `packleak nested` 84/167 → **1/1**.

⛔ What is left in this area is the borrowed pack element (`packleak alias`,
38/75) — section 3, the half that is bisected and not explained. Deeper than
three levels falls back to the repr walk: a leak, never a wrong free.
### How to test it — the part that is not optional

**A green suite on the generation that EMITS a change proves nothing.** Both
roots in this epic passed 1000+ cases on gen 1 and killed gen 2.

```bash
cp <clean>/bin/manticore bin/manticore          # a poisoned tree does NOT recover
rm -rf lib && cp -R <clean>/lib lib             # by reverting the source alone
bin/build && bin/build                          # gen 1, gen 2
./bin/manticore compile tests/aot/cases/closure_match_inlined.php -o /tmp/canary
```

That canary caught both. It is also the only honest bisect harness: reseed,
build twice, test — anything less attributes a gen-2 crash to the wrong commit,
which happened here (the alias fix was blamed for the pack's crash because the
two were in the tree together).

⚠ And **only the FULL suite** caught the alias fix's over-release: 28 filtered
families, 1000+ cases, were all green while `__mc_hosts_lookup_in` answered `''`
for every host after the first.

---

## 3b. The rest of the original plan, still open

- **Step 2 — make the leak table a GATE.** `LEAK on 0` should block a merge the
  way `failed 0` does. `tools/ownership_probe.php` already exits non-zero;
  wiring it into `bin/build --verify` / the fixpoint script is the whole job.
  Second, free detector: `MANTICORE_PROFILE=1` prints retain vs release per
  flavor and nothing reads it — assert the balance over the bench corpus.
- **Step 4 — finish `isFreshCellTemp`'s consumers.** Wired: call arguments,
  `echo`, the ~30 string builtins (through `$ptrArgCellByReg`) and now CASTS.
  NOT wired, each a one-cell-per-call leak: concat operands, comparison
  operands, array-literal elements, `store_element`, `return`.
- **Step 5 — the native json encoder for the flagged path.** `EmitLlvmBuiltins`
  takes `json_encode` natively only when `argIsDefaultInt($args, 1, 0)`, so ANY
  non-zero flag falls back to the compiled-PHP walker. `json_pretty`,
  `json_records` and `json_objects` are the slowest bench rows because of it.
- **A separate wrong answer, found and not chased:** `GenericType::parse` does
  not understand a parenthesized group, so `@return (string|null)[]` still draws
  the "bare `array`" analyzer warning even though the type now lowers correctly.
## 4. Traps this work paid for

- ⚠ **Two parallel string arrays, never one array of pairs.** A nested array
  element comes back ERASED, and concatenating that cell renders its raw word:
  `ptrtoint ptr 44565160096`, which is not even valid IR. It poisoned the binary
  and needed a reseed from main's compiler. This is why `emitCall` keeps
  `$rcArgRegs` / `$rcArgFlavs` parallel — now documented at `$arrArgTempRegs`.
- ⚠ **A failed `bin/build` poisons `bin/manticore` AND `lib/*.o`.** Recovery:
  `cp /path/to/main/bin/manticore bin/manticore`, `rm -rf lib && cp -R
  /path/to/main/lib lib`, then `bin/build` twice (the second so the new compiler
  builds itself).
- ⚠ **A user-code replica of a stdlib bug stays flat.** Both the property-slot
  veto and the arena accumulator reproduced only in the real callee: the arena
  verdict depends on escape analysis, and in a small replica the accumulator
  escapes. Patch a COPY of the real source instead —
  `MANTICORE_PRELUDE=<dir>` for the prelude, and for the stdlib the loop is
  **8 seconds**: edit `src/Runtime/*.php`, `bin/manticore build --libs-only`,
  recompile the probe.
- ⚠ **Only a REGISTER can be released.** A const-folded array literal is emitted
  as a global address literal; guard with `str_starts_with($reg, '%')`.
- The ~3 s Zend loop proves an emitter change with no rebuild:
  `MC_SRC=$PWD/src MANTICORE_PRELUDE=$PWD/prelude php -d xdebug.mode=off
  tools/compile_user_mir.php <x.php>` — it needs `MC_SRC`, and you read the
  `.ll`, not the MIR.
- A pipe eats the exit code: `bash tools/difftest.sh | tail` always reports 0.
  Redirect to a file.
- ⚠ **The Zend loop does NOT link the stdlib**, so a stdlib callee types as
  `unknown` there and `cell` in the real binary. A correct patch can look like a
  no-op in that dump — `json_encode`'s cast fix did, and was reverted once on
  the strength of it. For any type-GATED ownership decision, read the binary's
  IR (`--keep-ir`, which writes next to the `-o` path, not the source).
- ⚠ **A green suite on the generation that EMITS a change proves nothing.** Two
  changes in this epic passed 1000+ cases on gen 1 and SIGSEGVed gen 2. Reseed,
  `bin/build` twice, and compile `tests/aot/cases/closure_match_inlined.php` —
  that canary caught both, and it is the only honest bisect harness.
- ⚠ **A filtered sweep is not the suite.** 28 families and 1000+ cases were
  green while `__mc_hosts_lookup_in` answered `''` for every host after the
  first; only `bash tests/aot/run.sh -j 0` found it.
- ⚠ **`MANTICORE_DEBUG_VERIFY=1` can hide the bug it is meant to name.** The
  guards shift allocation, and one of these crashes stopped reproducing under
  them. A crash that survives verify with NO guard firing is not an rc<=0
  release of a tracked buffer — look elsewhere.

---

## 5. Open, not part of this epic

- `plausiblePtrIr` dereferences an unvalidated word at ~10 sites — a crash
  class, not a leak, and it blocks tagged arithmetic.
- `Compile\Mir\Effects` is one OBJECT PER NODE: 2.0M allocated, 0 freed. Make it
  an int bitmask, then `Parser\Ast\Span` (1.5M), then the AST itself, dead once
  MIR exists. The largest single number in the project.
- `count(array_keys($a))` still BUILDS the key array to count it — a fold to
  `count($a)`.
- The two standing difftest divergences.
