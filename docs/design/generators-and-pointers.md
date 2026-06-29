# Generators + an explicit frame pointer — design plan

Status: **design only, not built.** Generators are a large feature; this captures
the plan and the dependency on an explicit (non-rc) frame pointer so we build
them in the right order.

---

## 0. Profiling first — where time actually goes (2026-06-12)

A `sample` of the front-end compiling the whole `src/` (7.5 s, no clang):

| cost | share | what |
|------|-------|------|
| `__mir_concat_arena` (memmove + strlen) | **~67%** | building the LLVM IR text via `$out .= …` |
| method-dispatch resolution (`selfAndDescendants`, strcmp) | small | |
| assoc `get_str`/`isset_str` (strcmp) | small | |

`__mir_concat(a,b)` is `strlen(a)+strlen(b)+alloc(la+lb+1)+memcpy(a)+memcpy(b)` —
a **full copy of the accumulator on every append**, so building an N-byte string
by repeated `.=` is **O(N²)**. The compiler emits ~8 MB of IR this way.

**Conclusion for the pointers question:** the compiler's bottleneck is *string
building*, not array/numeric access — so a raw/typed-buffer **pointer would not
help the hot path**. The big, semantics-preserving win is **amortized `.=`**:
give the string header a `capacity` field and append in place when the LHS is the
sole owner (rc==1) with spare room, over-allocating ~1.5–2×. That turns the IR
build from O(N²) to O(N). This is the recommended near-term *speed* work and is
independent of generators.

Explicit pointers earn their place for **correctness** (generator frames), below
— not for compiler throughput.

---

## 1. Why generators need an explicit frame

A PHP generator suspends at `yield`, preserving its locals, and resumes later:

```php
function gen() {
    $x = compute();
    yield $x;           // suspend — $x must survive across the return
    yield $x + 1;
}
```

We compile to native code with a real C stack, which is destroyed on return.
So any local that is **live across a yield** cannot stay on the stack — it must
live in a heap-allocated **frame** that the generator object owns, and be
accessed through a pointer to that frame.

This is the classic **stackless state-machine** lowering (Rust/C# async,
Kotlin coroutines), preferred over stackful fibers here because it needs no
per-generator stack, no context-switch, and composes with our static ABI.

---

## 2. The explicit frame pointer

What a frame needs is a **heap struct accessed by a typed pointer**, with **no
refcount/descriptor overhead** (the generator object owns the frame for its
lifetime and frees it on completion). That sits between two things we already
have:

- `#[Struct]` — a *value* layout (header-less), but value-copied, not shared.
- objects — pointer-shared, but carry the `{descriptor, rc}` header + drop ABI.

The frame is the third point: **a pointer-shared, header-less heap struct**. The
mechanics already exist internally — by-ref params hold an address and
`load/store` through it (`refLocals`), and closures heap-allocate an env struct
(`[fn_ptr, cap0, cap1, …]`) addressed by pointer. Generators generalize this:

- A synthesized **frame layout** per generator: `[state:i64, live-local-0,
  live-local-1, …]` (only locals live across a yield; the rest stay on the stack).
- An **explicit typed pointer** `frame*` with `field(frame, k)` load/store at a
  computed offset — exactly the object-property mechanism, minus the rc header.

So the "explicit pointer" to introduce is a **frame/struct pointer in MIR**: a
typed pointer to a synthesized struct with typed offset access, non-rc, owned by
one holder. Reuse `propertyOffset`-style layout; reuse the `refLocals`
deref-load/store codegen.

---

## 2a. No new syntax — a `#[Ptr]` parameter attribute

Hard constraint: **the compiler source is valid PHP that runs under Zend** (the
cold bootstrap parses + executes it). New pointer *syntax* (`*p`, `&T`, Go-style)
would make Zend reject the source — the bootstrap breaks. So an explicit pointer
must be expressed in **ordinary PHP that Zend accepts and the compiler
reinterprets** — exactly the FFI pattern (`#[Library, Symbol]` + valid bodies).

**The clean surface: a `#[Ptr]` (or `#[Pointer]`) PARAMETER ATTRIBUTE.** PHP 8
attributes on parameters are valid syntax Zend already parses; they're inert at
runtime, and they sit right at the declaration — self-documenting, no docblock:

```php
function fill(#[Ptr] array $buf, int $n): void { /* … */ }
class Lexer { public function scan(#[Ptr] string $src): Token { /* … */ } }
```

- Under **Zend** (bootstrap): `#[Ptr]` is an inert attribute; the parameter is an
  ordinary by-value/by-handle PHP value — correct, just slow. Bootstrap intact.
- Under **Manticore**: the compiler reads the param attribute (like it reads
  `#[Symbol]` on functions) and passes the argument as a **raw typed pointer** —
  no rc, no COW, offset load/store on the callee side — and **borrow-checks the
  discipline at compile time** (single-owner, non-null deref, no escape past the
  call) — fails the *compile*, never the runtime. `#[Ptr]` is the pointer-cousin
  of `&` by-ref: by-ref already passes the slot address; `#[Ptr]` passes a
  pointer to the buffer with no refcount/CoW overhead.

**Caller mechanism — automatic, like `&` by-ref (no `ptr()`/`make()` wrapper).**
The call site is plain `f($buf)`; the `#[Ptr]` on the *parameter* drives the
compiler to pass a pointer (reusing the by-ref call-site path that already
forwards a slot address). A manual `ptr($x)` would have to return a real `Ptr`
object under Zend → the callee would see a `Ptr` under Zend but a raw pointer
natively → divergent bodies + a new runtime primitive. Automatic keeps the
**callee body identical** under both: it writes `$buf[$i]` / `strlen($buf)`,
which Zend runs by value and Manticore lowers to direct pointer-offset loads.

**`#[Ptr]` is an IMMUTABLE borrow** — this is what makes the dual identity hold:
no mutation through it, no escape past the call (borrow-checked at compile time),
so Zend's by-value copy is observationally equal to the native pointer (the
callee can't mutate it anyway). Mutation stays with `&` by-ref. The family:

- `&$x` — mutable slot reference (passes the slot address; already wired).
- `#[Ptr] $x` — immutable fast borrow (passes a buffer pointer; no rc, no CoW,
  offset reads). The speed lever for hot read-heavy buffers (lexer scan, etc.).

For non-parameter positions (a pointer return, a struct field, a generator
frame), a `Ptr<T>` value class is the fallback surface — but the parameter
attribute covers the common "read this buffer fast" case cleanly.

This is the **Go-like** sweet spot the philosophy leans toward: value types
(`#[Struct]`), explicit-but-checked pointers, single-owner memory, no GC —
through attributes, so the dual Zend/native identity holds with zero syntax risk.

## 3. Lowering (the state machine)

For each generator function `g`:

1. **Liveness across yields** — find locals live across any `yield`; they become
   frame fields. (A new analysis pass; the rest stay stack locals.)
2. **Frame struct** — synthesize `g$frame = { i64 state, <live locals> }`.
3. **Resume function** `g$resume(frame*) -> i64` — body rewritten as a `switch
   (frame.state)` that jumps to the instruction after the last yield; locals are
   frame-relative loads/stores.
4. **`yield $v`** — store `$v` into the generator's `current` slot, set
   `frame.state = <next>`, `ret` (suspend). Resume re-enters at `<next>`.
5. **`gen()` call** — allocate the frame, return a **Generator object** wrapping
   `{ frame*, resume-fn-ptr, current, key, finished }`.

## 4. The Generator iterator object

A small built-in class implementing the iterator protocol over the frame:
`current()`, `key()`, `next()`, `valid()`, `rewind()` (drives one `resume`),
`send($v)` (passes a value back into the suspended `yield` expression), and
`getReturn()`. `foreach ($gen as $k => $v)` desugars onto it.

`send`/`yield`-as-expression need the frame to carry an inbound slot too — fold
it into the frame struct.

---

## 5. Staged plan (build order)

1. **(separate, near-term) Amortized `.=`** — string `capacity` + in-place append.
   The biggest compiler-speed win; unblocks faster iteration on everything else.
2. **Frame-pointer abstraction in MIR** — synthesized non-rc heap struct + typed
   pointer + offset load/store, reusing the closure-env / refLocal codegen. Prove
   it on a hand-written frame before any yield transform.
3. **Yield liveness + state-machine transform** — the cross-yield liveness pass
   and the resume rewrite. Start with the simplest shape (no `send`, no
   try/finally across yield, `foreach`-only consumption); gate against `php`.
4. **Generator object + iterator protocol** — `current/next/valid/...`, then
   `send`, `getReturn`, keys.
5. **Hard cases last** — `yield from`, `finally` across a yield, exceptions
   through a suspended frame, references.

## 5a. Amortized `.=` — executable plan (step 1) — **SHIPPED (`2e921be`, `2df1906`)**

Done. Result: `sample` of `dump-llvm-mir src` shows `__mir_concat_arena`
fell from ~67% to **0 samples**; wall ~7.5s→~6.4s. The next bottleneck is
`manticore_explode`. Notes that bit during implementation:
- **ABI change needs two rebuilds.** Gen-1 `stdlib.o` is still old-ABI;
  the linkonce_odr string helpers dedup across `user.o`+`stdlib.o`, so an
  `alloc+16` paired with a stale `free p-8` corrupts the heap until gen-2
  rebuilds `stdlib.o` in the new ABI. Always run a second `bin/build`.
- **The hot accumulator was arena, not heap.** Escape analysis marked the
  IR-builder `$out .= …` confined (immortal, rc=-1), so the `rc==1`
  in-place path never fired. `InferAllocKind::isStringSelfAppend` now forces
  a string self-append accumulator to RC_HEAP (also gives it the scope-exit
  release the grown heap buffer needs — arena carries no per-object free).
- The `__mir_str_append` helper owns the old value's lifetime (releases on
  grow, keeps on in-place), so `emitStoreLocal` SKIPS release-before-
  overwrite for the `.=` shape — that's what preserves in-place identity.

The measured win. Today every `$s .= $x` lowers to `$s = Concat($s, $x)` →
`__mir_concat` which `strlen + alloc + memcpy(whole $s) + memcpy($x)` — O(n²) to
build an N-byte string. Strings are `[rc@-8, bytes@0]`, NUL-terminated, length
via `strlen`, **no capacity tracked** — so in-place append is impossible. Plan:

1. **String header gains `capacity`** — layout `[cap@-16, rc@-8, bytes@0]`. Keep
   `rc@-8` and `bytes@0` PUT (content/rc/strlen/memcpy/echo/substr all unchanged);
   only the malloc base + frees move `p-8 → p-16`. Contained set:
   - `__mir_str_alloc` / `__mir_str_alloc_arena` — reserve `n+16`, write `cap=n`
     at base, `rc` at base+8, return base+16.
   - string FREE sites (the `str` branch in `__mir_rc_release` + `__mir_rc_release_str`)
     — `free(p-16)` not `p-8`. (Find via the `tagp = p-8` reads.)
   - Immortal strings (literals `@.str.N` rc=-1, arena rc=-1) have NO cap field —
     fine: the append helper checks `rc==1` first and only then reads `cap`.
   - **Gate this layout change ALONE first** (capacity unused) — suite + difftest
     + fixpoint + stability. This is the heisenbug-prone step; prove it before the
     append path. (Lesson from the drop-ABI A' property-shift corruption: the
     low-risk shape keeps content/rc offsets fixed, only base/free move.)
2. **`__mir_str_append(s, b) -> s'`** — if `rc(s)==1` and `strlen(s)+strlen(b) <
   cap(s)`: `memcpy(b)` at `s+strlen(s)`, NUL-terminate, return `s` (in place).
   Else alloc a new buffer over-allocated to ~`2*(la+lb)` (set its `cap`), copy
   `s`+`b`, return it (old `s` released by the StoreLocal's release-before-overwrite).
3. **Emit hook** — in `emitStoreLocal`, detect value = `Concat(LoadLocal(name),
   rhs)` (self-append), and emit `store(name, __mir_str_append(load(name), rhs))`
   instead of a fresh concat. Falls back to plain concat for non-self targets.
4. **Gate** after the append path; verify the front-end compile time drops
   (re-`sample`: `__mir_concat` should fall off the top).

Risk: the string-header change is in the rc/string heisenbug zone — do steps in
order, gate each, revert on any non-determinism (per the drop-ABI discipline).

## 6. Risks / notes

- Liveness-across-yield must be conservative — a missed live local = a
  use-after-return. Gate hard (difftest + a generator corpus).
- The frame is the heisenbug-adjacent area (new heap object + pointer); keep it
  non-rc and single-owner to avoid the rc/drop interactions.
- Where the compiler itself wants generators (e.g. streaming tokens from the
  lexer, walking AST/MIR lazily) is the dogfood target — but only after the
  amortized-`.=` win, so the compiler is fast first.
