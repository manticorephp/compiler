# Handoff — the ownership contract for builtins, and what is left of it

**Branch** `cellargleak`, forked from local `main` `7f5fd73`.
**Commits** `8c71290` · `19ef708` · `113c87c` · `d751ff0` · `8a00f7d`.

Read this before touching rc/ownership code again. It is written for a session
that has none of the context, so it starts from the model and ends with a
numbered plan.

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

| shape | before | after |
|---|---|---|
| `http_parse` / `http_scale` | 198.8 / 374.0 MB | **2.7 / 2.8** |
| `json_utf8` / `json_pretty` / `json_decode_object` | 80.0 / 55.8 / 27.0 MB | **6.0 / 4.7 / 6.0** |
| `count` / `array_values` / `array_keys` / `array_reverse` / `array_slice` / `max` / `min` over a fresh temp | 159–360 MB | **flat** |
| `strlen(json_encode($s . $i))` | 151 MB | **1.8** |
| a 6-builtin probe over `json_encode` | 472 MB | **2.1** |

Roots closed, each with its own memory entry:

1. `$s[$i]` on a property lowers to `__str_byte_at`, a name `callKeepsNoArg` had
   never heard of, so ONE `byteAt()` method vetoed release-on-overwrite for its
   string property program-wide.
2. A STRING box re-tags the SAME pointer, so a fresh string handed to a
   `mixed`/cell param had no owner (`emitCall`'s tagged arm, `cellBoxTempDrop`).
3. The DYNAMIC-PROPERTY BAG was never released — `dropRuntimeBody` walks
   `propertyNames` and the bag is not one. `clone` shares the pointer, so it now
   retains.
4. A fresh CELL temp had no owner: only a DISCARDED result was dropped.
5. `isStringSelfAppend` read only the IMMEDIATE left child, so `$s = $s . $x . $y`
   stayed ARENA and `__mir_str_append`'s grow arm moved it to the heap, where
   `arena_leave` cannot free it.
6. `__mir_object_vars` is `internal` and specialized from the EMITTING module's
   class table, so `(array)$v` inside `manticore_stdlib.o` could not see a user
   class and `json_encode($obj, ANY_FLAG)` printed `{}`. The producer side
   (`@__mir_props_<id>` on the descriptor) was already in the tree; only the
   consumer was missing.
7. The builtin ownership contract above.

**Gates at `8a00f7d`**: suite **1043/1045, failed 0**; bench LEAK table **28
scaled, LEAK on 0**; difftest **MATCH 958, DIFF 2** — both DIFFs verified
pre-existing on main (`error_handler_basic`, `trigger_deprecation_shape`);
timing bench shows no regression.

**Not run**: `tools/selfhost_fixpoint.sh`, the LINUX gate, amd64.

---

## 3. The plan, in order

### Step 1 — build the prober FIRST (the whole point of this handoff)

Every leak above was found by hand: LEAK ratio → split the loops → cut the
callee → read the `.ll`. That does not scale to the ~40 names left. Build
`tools/ownership_probe.php`:

- for each builtin name in a table, generate a probe:
  `for (…) { $acc += <consumer>(<builtin>(<fresh array/string temp>)); }`
  where the fresh temp is `explode(",", $s . $r)` (an array) or
  `json_encode($s . $r)` (a cell) or `$s . $r` (a string);
- compile, run at 1x and 2x work, report the RSS ratio — the same arithmetic
  `bench/run.sh LEAK=1` already uses (`ratio ~2.0` = nothing is freed);
- diff the output against `php` for the SAME probe.

The two columns together are the classifier: **ratio flags a leak, parity flags
an over-release.** Print a table of `name → class A/B/C → leaks? → parity?`.
That table replaces the manual bisect for every remaining name.

⚠ A probe statement must be an INT no-op (`$maxDepth = $maxDepth + 0;`), never a
self-assign (`$out = $out;` has its own rc shape and lies), and never a bare `;`
(the parser rejects it).

### Step 2 — make the leak table a GATE

`LEAK on 0` should block a merge exactly as `failed 0` does. Add it to
`bin/build --verify` / the fixpoint script. Second, free detector: the
`MANTICORE_PROFILE=1` counters already print retain vs release per flavor, and
`array_retain_cell=100000` against `array_release_cell=0` named root 7 in a
single run. Nothing reads them today — assert the balance over the bench corpus.

### Step 3 — convert the remaining names, class by class

Run the Step-1 table and work down it.

- **A (safe immediately)** — `in_array`, `array_key_exists`, `array_sum`,
  `array_product`, `array_is_list`. They measure flat TODAY only because a
  cellify rebuild frees the source for them. Make it explicit via
  `emitArrPtrArg` so a future change to the rebuild cannot silently reopen them.
- **B (copy must co-own)** — `array_merge`, `array_flip`, `array_unique`,
  `array_combine`, `array_fill_keys`, `array_diff`, `array_intersect`,
  `array_map`, `array_filter`, `str_split`, `preg_split`. For each: add the
  element retain in the copy loop, convert the operand to `emitArrPtrArg`, and
  add the name to `builtinMintsOwnedArray` if its result is a fresh array.
- **C (result is an element)** — `current`, `key`, `reset`, `end`, `next`,
  `prev`, `array_pop`, `array_shift`, `array_search`, `array_first`,
  `array_last`, `array_key_first`, `array_key_last`. Each needs the winner
  retained and the name added to `isFreshCellTemp`.

### Step 4 — finish `isFreshCellTemp`'s consumers

Wired: call arguments, `echo`, and the ~30 string builtins (through
`$ptrArgCellByReg`). NOT wired, each a one-cell-per-call leak: concat operands,
comparison operands, array-literal elements, `store_element`, `return`.

### Step 5 — the native json encoder for the flagged path

`json_pretty` 1.8×, `json_records` 1.8×, `json_objects` 1.5× are the slowest
rows of the bench, and the cause is one line: `EmitLlvmBuiltins`'s dispatch takes
`json_encode` natively only when `argIsDefaultInt($args, 1, 0)` — ANY non-zero
flag falls back to the compiled-PHP walker. A native path that understands
`JSON_PRETTY_PRINT` and the escape flags moves four rows at once. Now cheap to
attempt: the walker's memory behaviour is finally understood.

---

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
