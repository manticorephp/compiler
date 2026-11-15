# Stdlib `.o.sig` coverage for the semantic core

Spike for P2a (Tasks 5, 6). Measured on `lib/manticore_stdlib.o.sig` (schema 2, abi 24) by
reading its JSON directly (the same document `Sig::declsFromJson` / `classDeclsFromJson` consume).

## What the sig contains

Top-level keys: `functions` (1884), `classes` (6), `constants` (0), `libs`, `weak`, `dynf`.

- **Functions** carry `name, symbol, params[], ret, overload?`. A param carries
  `name, type, byref, refout, cellarg, variadic, default?`. Defaults are `{k,v}` with
  `k` in `null|int|bool|str|float` (488 params have one). No arrays or constant expressions.
- **Classes** (full `ClassDef` view: `parent, ifaces, final, abstract, props, sprops, methods,
  meta, pmeta, consts, attrs, layout`) exist for only **6** stdlib classes:
  `HashContext`, `DeflateContext`, `InflateContext`, `__McCtypeTable`, `__McHtmlTables`,
  `__McPcreCache`. All other PHP classes/interfaces (about 285 declarations, ArrayObject,
  DateTime, Exception family, Ds, ...) and about 1405 functions live in `prelude/*.php`
  and are compiled into each program, so they are **not in the sig at all**.
- Prelude-owned functions (`array_map`, `array_filter`, `explode`, ...) are absent from the
  sig; stdlib-owned ones (`strlen`, `preg_match`, `str_replace`) are present.
- `meta` (methods) carries `n, vis, static, abstract, final, rethint, decl, attrs,
  params[{n, hint, hasdef, byref, variadic, promoted, attrs}]`; `pmeta` (props) carries
  `n, vis, static, ro, hint, hasdef, decl, attrs`; `consts` carries `{n, vis, v}`.

## Coverage table

| Field | Functions (1884 in sig) | Classes in sig (6) | Verdict |
|---|---|---|---|
| PHP-level hint strings | `type`/`ret` are the LOWERED type: `int`, `string`, `mixed`, `int[]`, `array<string,mixed>`, `closure`, `\Ffi\Ptr`. Unions, nullables, `callable`, `?T`, `self`, class names collapse (`int\|string` -> `mixed`, `callable` -> `closure`; 250 returns are `mixed`) | `meta.rethint`, `meta.params.hint`, `pmeta.hint` keep the source hint string | functions: **lossy**; classes: **present** |
| Parameter names | present | present (`meta.params.n`) | **present** |
| Parameter defaults | literal scalars/null only (`k`,`v`); constants/arrays/expressions absent. `hasdef` only for methods (no value) | `hasdef` bool only, no value | functions: **lossy**; methods: **absent** (value) |
| Variadic / by-ref | present (`variadic`, `byref`; plus ABI flags `refout`, `cellarg`) | present | **present** |
| Visibility / static / abstract / final | n/a (functions) | present (`vis, static, abstract, final`, class `final, abstract`) | **present** |
| Docblocks | absent | absent | **absent** |
| Generics (`@template`, `T[]`, `array<K,V>`) | only as lowered shape strings (`int[]`, `array<string,string>`); no template params, no docblock-derived hints beyond what lowering kept | absent | **lossy** |
| Constants | `constants` is empty: global `const`/`define` not exported | `consts[{n,vis,v}]` present for classes, value is encoded scalar only (`encodeValue`), expression constants dropped | global: **absent**; class: **lossy** |
| Promoted properties | n/a | `meta.params.promoted` (string) and `pmeta` present | **present** |
| Attributes | absent | `attrs` names only (no args) | functions **absent**; classes **lossy** |
| Source ranges / file / line | absent | absent | **absent** |
| Parents / interfaces | n/a | `parent`, `ifaces` present | **present** |

## Consequences

1. The sig is a link-ABI interface, not a language-service model. It is usable for names,
   arity, by-ref and variadic flags, and an approximate type, for the stdlib-owned function
   half only.
2. **`SourceType` (Task 5) cannot be built from the sig for functions**: it would see
   `mixed`/`closure` where the source says `int|string` or `callable|null`. Take PHP-level
   hints, defaults, docblocks and generics from the **AST** of the defining file instead.
3. Because `src/Runtime/Stdlib/*.php` (62 files) is the source of the sig functions, and
   `prelude/*.php` (49 files) is the source of everything else, the stdlib sources are
   the only complete origin for hover/definition: the sig lacks source positions and docblocks
   altogether, so go-to-definition and hover docs need the ASTs regardless.
4. Task 5/6 must therefore parse **both** `prelude/*.php` and `src/Runtime/Stdlib/*.php`
   (tolerant parser) for declarations. The sig is at best a fast pre-filter / cross-check
   (name existence, by-ref flags, `overload` aliases such as `array_fill__int` -> `array_fill`, which
   must be filtered out of user-visible symbols).
5. Classes: for the 6 sig classes the sig is richer than for functions, but all are internal
   (`__Mc*`, contexts), so it buys nothing for the editor. All user-visible classes come from prelude ASTs.
6. Global constants are in neither the sig nor (necessarily) a declaration form the analyzer
   sees; take them from the prelude/stdlib ASTs.

## What still must come from the prelude / stdlib ASTs

PHP-level hint strings, default expressions, docblocks (`@param/@return/@template`),
generics, attributes with arguments, all class/interface/trait/enum declarations
except the 6 internals, global constants, and every source range.
