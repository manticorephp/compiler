# Manticore — Status & Roadmap

**Single source of truth for "where the compiler is and what's next."** Supersedes
`docs/bootstrap/13`, `docs/bootstrap/17`, `docs/bootstrap/README.md`, and
`docs/PHP85_SYNTAX_GAP.md` (all deleted). Design *how-it-works* docs are listed
under [Design references](#design-references) and remain valid.

_Last updated: 2026-07-15 · branch `main` · HEAD `a6e062a`._

## Current state

Pure-PHP, self-hosting PHP→native AOT compiler. The runtime is emitted as LLVM IR from PHP; `bin/manticore` compiles its
own `src/`.

**Gates (all green):**
- `tests/aot/run.sh` — **467/467**
- `tools/selfhost_fixpoint.sh` — fixpoint byte-identical · self-host · stability 5×2
- `tools/difftest.sh` — **458 match / 0 diff** vs PHP 8.5.8

Build: `bin/build --seed` (cold Zend bootstrap → native) / `bin/build`
(self-host). See `.claude/CLAUDE.md` for the pipeline and key files.

**~228 stdlib functions** implemented (array/string/type/math/ctype/`preg_*`/JSON/
var/SPL/date/IO); see the README's Standard library table.

**Recently completed** (2026-07): full `preg_*` family over host PCRE2 +
`#[RefOut]` out-param auto-vivification · Monomorphize **callable dimension**
(specialize callback-takers per concrete closure) · **de-cellify** at the
concrete-array ← cell-array store boundary — fixes `uasort` with any comparator
and dents the erased-array representation root · Ryu float formatting · JSON
decode 3.3× + default-flag escaping · assoc key-leak fix (malloc −97%) ·
reified-class generics · pin elimination (278→0). Detail in the session memory
files under `.claude/.../memory/`.

## The gap matrix (probed, with repros)

Each row is a concrete `tests/aot`-style repro diffed against `php`. Re-probe
before starting — turn "what's left" into a worklist.

### Tier 1 — correctness bugs (silent wrong output — fix first)

| Gap | Repro | Today | Want |
|---|---|---|---|
| `int += float` in a loop | `$s=0; for(...) { $s += 1.5; }` | accumulator stays int-typed → reads float bits as garbage | promote the loop-carried local to float. NOTE: a loop-body **re-inference fixpoint** is a DEAD END — re-running `inferNode(body)` corrupts `cellMergeLocals` (the InferTypes "heisenbug zone") and SIGSEGV'd the self-host. Needs a separate per-local float-slot analysis (a local ever float → float slot, int stores coerce), not loop re-inference |
| `/` exact-int (variable) | `$a/$b` (both int, divisible) | `float` | `int` — literal `6/2` now folds to int; variable stays float (a numeric-cell result cascades — low value) |

_Done: **late static binding** — `static::class`, `new static`, `static::method()`,
`static::$prop`, `parent::`/`self::` forwarding, ctor LSB (per-descendant
monomorphised copies `<owner>__<m>__lsb<sub>`; `docs/design/late-static-binding.md`).
**Encapsed strings** — `"$o->n"`, `"$a[$k]"`, `"{$this->arr[0]}"` all interpolate;
the lone gap was bare-`array` **property** element erasure (now recovered from a
homogeneous list-literal default). **`get_class()`** returns the runtime class
via a class_id switch (not the static type). **Polymorphic object arrays** —
`[new D, new C]` of unrelated classes sharing an interface now unifies to
`obj<Iface>` (was element-erased → raw), so `$arr[0]->m()` / `foreach` dispatch
virtually. **Untyped factory chain** — `A::create()->name()` where `create()`
has no return hint now infers the object return (every return same class) →
chains resolve. **Literal int division** — `6/2` folds to int(3) (non-exact /
variable stay float)._

### Tier 2 — missing PHP 8.5 syntax (blocks real code / self-host breadth)

_All previously-tracked syntax gaps are now closed (property hooks, asymmetric
visibility, anonymous classes, heredoc/nowdoc, pipe `|>`, first-class callables,
DNF types, enums, match, named args). The parser covers the PHP 8.5 surface the
self-host needs; remaining gaps are semantic (see Tier 1 + the README limitations),
not syntactic._

_Done: **property hooks** (`public string $x { get => …; }`) + **asymmetric
visibility** (`public private(set) $x`). **anonymous classes** (`new class(args) extends X implements Y { … }`) —
parsed under a synthetic name, hoisted to top-level, lowered like any class;
fixed two surfaced abstract-method bugs (dispatch to a body-less method; abstract
`: float` return untyped). **heredoc / nowdoc** (`<<<EOT … EOT;`, `<<<'EOT'`) —
lexer normalises the
body to a double-/single-quoted StringLiteral so the parser reuses interpolation
/ literal handling; PHP 7.3 flexible (indented) closing marker with per-line
dedent supported. **Pipe operator `|>`** — left-assoc, below `??` / above the
ternary; the RHS callable is desugared by shape (FCC `f(...)`/`$o->m(...)`/
`C::m(...)`, string `"fn"`/`"C::m"`, array `[$o,"m"]`/`["C","m"]`, else dynamic
Invoke). **First-class + literal callables** — `f(...)`/`$o->m(...)`/`C::m(...)`
as stored values, builtin FCC (`count(...)`), and literal `"fn"(x)`/`[$o,"m"](x)`
invoked directly._

### Tier 3 — callable values

_Done: first-class callables (`f(...)`/`$o->m(...)`/`C::m(...)`) as values,
literal `"fn"(x)`/`[$o,"m"](x)` invoked directly, `call_user_func` /
`call_user_func_array`, a string/array literal bound to a `callable` param
coerced to a closure at the call site (`array_map("strtoupper",…)`, `usort($a,
"cmp")`), and a local holding a callable literal invoked directly (straight-line
const-prop; `[$o,"m"]` dispatches on the `$g[0]` snapshot)._

_Already done: clone-with, DNF types, yield from, first-class callables,
match, enums, attributes, nullsafe, named args._

### Tier 3 — runtime dynamic resolution (one epic, deferred)

A family of features that all need the same missing piece — a **runtime
name→thing dispatch table** generated at compile time (string-compare on the
name → the matching symbol). Worth doing together, once, rather than piecemeal:

- **Dynamic function call** — a non-literal computed callable: `$f = "str" . $x;
  $f(…)`, `call_user_func($computed, …)`. (Literal / FCC / local-const callables
  already work — Tier 3 callables above.)
- **Dynamic class name** — `new $cls()`, `$cls::method()`, `$cls::CONST`,
  `$obj instanceof $cls`, where `$cls` is a runtime string.
- **Dynamic method / property** — `$o->$m()`, `$o->$p` (DynProp partially
  exists), `$cls::$staticProp`.
- **Reflection Tier-2** — `ReflectionClass`, runtime attribute reads (Tier-1
  compile-time class queries already fold).

Design sketch: emit one `__manticore_call_named(name, argc, args…)` (and
`__manticore_new_named` / `__manticore_static_named`) switching over all known
user symbols; a string-typed callee / class-name at an Invoke / New / static
site routes through it. Bounded by a fixed max arity (uniform cell args).
Decide the table's membership (all user fns/classes, or only those that escape
to a dynamic site).

### Tier 3 — semantic depth

- Reflection Tier-2: `ReflectionClass`, runtime attribute reads, dynamic class
  names (Tier-1 compile-time class queries already fold — see
  `session_handoff_2026_06_19`).
- `__debugInfo`, `serialize` / `unserialize` family.
- Dynamic property store `$o->$key = …` (blocks `src/Runtime/Json.php`).
- `unset` of a packed vec element (hole / shift semantics).
- `echo`/concat of `INF`/`NAN` renders lowercase (var_dump fixed).

### Tier 4 — performance & infra (everything already beats Zend)

- SSO / interning for dynamic small strings; property-bag literal hashes.
- Harden the gated `TypeCheck` pass (`MANTICORE_TYPECHECK=1`) toward on-by-default
  — it would have caught the `str_replace`-array misuse at compile time instead
  of as a runtime SIGBUS.

## Recommended order

1. ~~Late static binding~~ — **done** (see Tier 1).
2. ~~Encapsed strings + `get_class()` runtime class~~ — **done** (see Tier 1).
3. ~~Heredoc/nowdoc~~, ~~pipe `|>`~~, ~~anonymous classes~~ done. Tier 2 grammar
   left: property hooks + asymmetric visibility (8.4).
4. Tier 3 / Tier 4 as drivers demand.

## How to build the plans (the method that works here)

1. **Probe-matrix first.** One minimal repro per feature, diffed against `php`,
   before committing to a design. (This file's matrix is the template.)
2. **Find the convergent root.** Monomorphization was *the* root behind a dozen
   erasure symptoms. Ask "is there one fix that collapses several rows?" before
   building.
3. **Phase + gate hard, every phase:** suite + difftest + fixpoint + stability +
   `--seed`. Never batch risky changes. A "random transient" can be a real latent
   bug (the str_replace SIGBUS) — chase it, don't dismiss it.
4. **Dual-validate Zend seed AND native** — they diverge on strings / floats /
   by-ref. Some bugs only surface in the native self-build.
5. **php-faithful signatures; root cause over workaround** (memory
   `keep-php-signatures`, `fix-root-causes-not-reverts`).
6. **Self-host is the gate; real programs are the probes** — the compiler and
   stdlib are the quality corpus.

## Design references (current, keep)

- `docs/design/monomorphization.md`, `docs/design/monomorphize-callable-dim.md` —
  the generics/erasure engine + the callable dimension.
- `docs/design/type-system-v2.md`, `docs/design/unknown-cell-soundness.md` —
  cell / union / NaN-box type system + the erased-representation soundness epic.
- `docs/design/generators-and-pointers.md` — generators.
- `docs/design/module-system.md`, `docs/modules.md` — modules / manifest build.
- `docs/design/build-and-packaging.md` — packaging.
- `docs/design/ptr-attribute-plan.md`, `docs/ffi.md` — typed FFI.
- `docs/memory.md`, `docs/bootstrap/{10,11,12,14}` — memory model / rc / CoW /
  cycle collector / ABI contract (`12` is the "stone tablet").
- `docs/attributes.md` — attributes.
