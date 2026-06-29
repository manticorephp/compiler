# Generators — executable implementation plan

Status: **DONE (practical feature set), 2026-06-12.** Design rationale (why a
stackless state machine, why a non-rc frame pointer) is in
`generators-and-pointers.md §1–4`. Decision: the `#[Pointer]` PARAM attribute
is a marginal rc-elision and was DROPPED; the real foundation is the generator
**frame pointer**, built directly here.

## SHIPPED — works 1:1 with `php` (gates: suite/difftest/fixpoint/stability)

Commits `086070c` (parse) → `6db763d` (frame+state-machine+foreach) →
`9506f6b` (typed yields) → `324bc41` (declared `Generator<T>`, rc-exclusion) →
`d327759` (send / yield-value / explicit keys+key-type / getReturn / Generator
method protocol) → `43f2543` (`yield from` + foreach-with-yield, frame-backed
iterator state) → `035324e` (generator methods).

A generator = TWO functions: a creator `@manticore_<name>` (heap-allocs the
frame, returns it as the Generator value) and a resume
`@manticore_<name>$resume(frame*)` — a `switch(state)` state machine that
re-enters past the last yield. Frame (non-rc heap struct):
`[resume_fn@0, state@8, current@16, key@24, nextkey@32, sent@40, retval@48,
locals@56+]`. Generalizes the closure-env GEP codegen. Covered: `yield`,
`yield $v`, `yield $k=>$v` (+ key type), straight-line / while / for, early
return, multi-var state, string/obj/float values (typed via `Generator<T>`
inference + declared hint), typed params, `send()`/yield-as-value, `getReturn`,
the iterator protocol as method calls (current/key/valid/next/rewind/send/
getReturn — current/key/valid prime a fresh gen), `foreach`, reuse,
foreach-with-yield, `yield from` (generator / vec / assoc), generator METHODS,
empty generators. Practically leak-free (200k instances + 1M yields → flat
17 MB).

## NOT done (documented limitations — rarer / advanced)

- **Closure generators** (`$g = function(){ yield 1; };`) — closures are
  isolated from generator detection (sawYield reset around them), so a yield in
  a closure currently errors at emit. Needs the closure-lowering path to flag +
  emit a generator closure.
- **Exceptions / `finally` across a yield**, and **`throw()` into a generator**.
- **By-reference yield** (`yield` of/by `&`).
- **Precise rc lifetime**: the frame and rc-typed yielded values are not
  freed deterministically (bounded — memory stays flat in practice, no O(n)
  blowup). A proper fix gives the frame an rc header + a drop that releases its
  rc locals.
- **Stage D liveness**: ALL locals currently go in the frame (correct, but
  larger frames). An optimization to frame only cross-yield-live locals.

## (historical) executable build order

## What already exists (anchors)

- **Lexer:** `yield` is already a keyword (`src/Lexer/Lexer.php:54`). No lexer
  work.
- **Frame-pointer codegen model — reuse:** a closure is already a non-rc heap
  struct `[fn_ptr, cap0, cap1, …]` allocated via `__mir_alloc` and accessed by
  `getelementptr inbounds i64, ptr %env, i64 <slot>` (`emitClosure`,
  `EmitLlvm.php:2017`; env unpack at `:862-892`). The generator FRAME is the
  same shape `[state, local0, local1, …]` — generalize this codegen.
- **Expression-keyword parse model:** `parseThrow` (`Parser.php:1062`) shows the
  keyword→node pattern; `yield` is an EXPRESSION (`$x = yield $v`), parsed in
  the expression grammar at low precedence (below assignment).

## What is greenfield

Parser yield handling, an AST `Yield` node, generator detection, the whole MIR
state-machine lowering, the Generator object + iterator protocol, and
`foreach` over an object/iterator (no iterator-foreach today — array-only).

## Build order (gate each)

### Stage A — front-end `yield` (low risk)
- AST: a `Yield` expression node (`key?`, `value?` — covers `yield`,
  `yield $v`, `yield $k => $v`; `yield from $x` deferred to Stage E).
- Parser: handle `yield` in the expression parser (low precedence). A `yield`
  with no operand is valid (`yield;`).
- Generator detection: a function/method whose body contains a `yield` is a
  generator — flag it on the AST fn decl (a walk during parse or lower).
- Codegen: for now a generator fn emits a clear "generators not yet lowered"
  compile error (so non-generator code is untouched).
- **Gate:** suite + difftest (must be byte-neutral for yield-free code) + a
  `dump-ast` test that a generator parses and is flagged.

### Stage B — frame + state machine (single + multi yield; NO liveness yet)
Correctness-first: put **every** local in the frame (skip liveness — Stage D).
- Synthesize per-generator frame `g$frame = { i64 state, <all locals> }`.
- `g$resume(frame*) -> i64`: body rewritten as `switch (frame.state)` jumping to
  the instruction after the last executed yield; locals are frame-relative
  loads/stores (reuse the closure-env GEP codegen).
- `yield $v`: store `$v` into the generator's `current` slot, set
  `frame.state = <next>`, `ret` (suspend). Resume re-enters at `<next>`.
- Drive `resume` manually from a test harness first (before the iterator
  object) to prove the state machine.
- **Gate:** a hand-driven generator matches an expected resume sequence.

### Stage C — Generator object + iterator + `foreach` (the MVP end-to-end)
- A built-in `Generator` class wrapping `{ frame*, resume_fn, current, key,
  finished }`; `current()/key()/next()/valid()/rewind()` drive one `resume`.
- `gen()` call: allocate the frame, return a `Generator`.
- `foreach ($g as $k => $v)` desugars onto the protocol (this also adds the
  first object/iterator `foreach` path — array foreach stays as-is).
- **Gate HARD:** the canonical example below matches `php`; suite + difftest +
  fixpoint + stability.
  ```php
  function count_to(int $n) { $i = 1; while ($i <= $n) { yield $i; $i = $i + 1; } }
  foreach (count_to(3) as $v) { echo $v, "\n"; }   // 1 2 3
  ```

### Stage D — cross-yield liveness (optimization)
Only locals live across a yield go in the frame; the rest stay stack locals. A
conservative liveness pass — a missed live local is a use-after-resume, so gate
against a generator corpus + difftest.

### Stage E — hard cases (last)
`yield $k => $v` keys, `send($v)` (inbound slot in the frame; `yield` as an
expression value), `getReturn()`, `yield from`, exceptions through a suspended
frame, `finally` across a yield, references.

## Risk / discipline
The frame is a new heap object + pointer — heisenbug-adjacent. Keep it **non-rc
and single-owner** (the Generator owns it, frees on completion) to avoid rc/drop
interactions. Gate fixpoint + stability at Stage C and after any frame-layout
change (drop-ABI discipline). Liveness (Stage D) must be conservative.
