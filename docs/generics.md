# Generics

PHP has no native generic syntax, so Manticore reads generics from **docblocks**
(`@template`, `@var C<T>`, `@param T`, `@return T`, `T[]`, `array<K,V>`) and the
`callable(A): R` shape. Two lowering strategies back the same annotations —
**erasure** (one shared body, type variable rides as a tagged cell) and
**reification / monomorphization** (a concrete specialized copy, zero boxing) —
and the compiler picks the fast one wherever it is sound.

Every generics feature below has a worked case under `tests/aot/cases/generics_*`.
When probing correctness, use **`float`**: a `string`/object value survives
erasure by accident (a cell carries its own tag), so only a float (or the `2^50`
raw-int probe) exposes a representation bug.

## Docblock first — inline `<…>` is an extension

Generics are **docblock-driven by design**, so the source stays valid PHP and
runs unchanged under Zend `php` (which the cold bootstrap and `tools/difftest.sh`
rely on): `/** @param Pt[] $pts */` or `/** @var Box<float> $b */`.

Manticore also parses an **inline** generic type in a type position —
`function dump(array<Pt> $pts)`, `Box<float>` — as a compiler extension
(`array_of_class_generic.php`). It is fully supported, but it is **not valid PHP
syntax**, so a file using it no longer runs under Zend. Prefer the docblock form
unless the file is Manticore-only; reach for inline `<…>` only when you have
opted out of Zend compatibility.

## 1. Erased class generics — `@template`

```php
/** @template T */
final class Box {
    /** @var T[] */    private array $items = [];
    /** @param T $x */  public function add($x): void { $this->items[] = $x; }
    /** @return T */    public function get(int $i) { return $this->items[$i]; }
}

/** @var Box<float> $b */
$b = new Box();
$b->add(1.5);
echo $b->get(0) + $b->get(1);   // float arithmetic, correct
```

**One compiled `Box`** serves every instantiation. Inside the shared body `T` is
erased and travels as a tagged cell; the **call site** knows the binding
(`Box<float>`) and refines the result of `get()` from it — so `+` does float math
and `.` does string concat, not raw-i64 nonsense. Bindings can be scalars,
objects, or other classes (`Box<int>`, `Box<string>`, `Box<Tag>`).

## 2. Bounds — `@template T of C`

```php
/** @template T of Animal */
final class Pen { /* … @param T / @return T … */ }
```

A bound is not just a check — it changes codegen. An **unbound** `T` knows nothing
about its value, so it erases to a tagged cell. A **bounded** `T of Animal` is
known to be an object, so it erases to `obj<Animal>` — a raw pointer, no boxing.
The bounded form emits **zero** boxing ops where the unbound one emits several,
and `->speak()` dispatches virtually on the real runtime class.

## 3. Defaults — `@template T = X`

```php
/** @template T = int */
final class Counter { /* … */ }

$c = new Counter();   // no <…> at the use site → T binds to int
$c->add(10);
echo $c->get(0) + $c->get(1);
```

## 4. Inheritance — `@extends` / `@implements`

```php
/** @template T */                     /** @template T */
abstract class Base { /* @return T */ } interface Coll { /* @return T */ }

/** @template T @extends Base<T> */    /** @template T @implements Coll<T> */
final class Bag extends Base {}         final class ListColl implements Coll { /* … */ }

/** @var Bag<float> $f */  $f = new Bag();      // reaches Base as Base<float>
/** @var Coll<string> $c */ $c = new ListColl();
```

The generic member is declared on the base/interface; the receiver binds the
child's parameters, and climbing the chain re-maps the arguments (`Bag<float>` →
`Base<float>`).

## 5. Generic traits — `@use T<X>` (zero-cost)

```php
/** @template T */
trait Items { /* @var T[] / @param T / @return T … */ }

final class Floats { /** @use Items<float> */ use Items; }
final class Names  { /** @use Items<string> */ use Items; }
```

This is the one place generics buy speed for free. A trait is **copied** into each
using class, so the binding is substituted **at the source**: `T` never becomes a
type variable — it lowers straight to `float`/`string`, every member comes out
concrete, and **zero** boxing ops are emitted. Prefer a generic trait over a
generic class when each binding lives in its own class.

## 6. Reified class generics — a real specialized class

When a site **owns the construction** of a bound container, Manticore builds a
**real specialized class** instead of the erased body:

```php
/** @var Box<float> $b */
$b = new Box();        // this site constructs it → Box<float> is reified
```

The specialization has `float`-typed properties and a `float`-typed body — **zero**
boxing outside the erased thunks. Properties:

- A specialization is a **subclass of its origin**, so `instanceof`, `catch`, and
  compile-time dispatch already see it, and it reports its **origin's** name to
  `get_class()` / `::class`.
- **Static properties** stay on the origin (one slot per class, shared by every
  binding) — a specialization declares none.
- **The erased boundary is bridged by thunks.** A bare `Box $b` has no binding, and
  `Box<float>::get` returns a raw double while `Box<string>::get` returns a raw
  pointer — both i64, indistinguishable to that caller. Each specialized method
  gets a second entry (the erased thunk) that boxes its result / unboxes its args;
  the dispatch switch calls the thunk when the receiver is erased.

**A type hint may NOT name a specialization it does not construct.** A slot typed
`Box<float>` that is *handed* an object from elsewhere (which may be an erased
instance) stays **erased** and goes through the thunks. Only the `@var … = new …`
pair — where the site both declares and constructs — reifies. (`generics_reified.php`,
`generics_reified_fields.php`.)

## 7. Implicit generics — monomorphization (no annotation)

Even without `@template`, a function whose behaviour depends on an **erased**
parameter — a bare `array` with an unknown element, or a `callable` — is
specialized per concrete call-site shape:

```php
function first(array $a) { return $a[0]; }
first([1, 2, 3]);         // → first$mono over vec[int]
first(["a", "b"]);        // → first$mono over vec[string]

usort($rows, fn($a, $b) => $a->k <=> $b->k);   // usort specialized per closure
```

`Monomorphize` clones the callee per distinct concrete argument shape (array
element type, or the identity of a concrete closure), re-types each copy, and
repoints the call — so a helper used over `int[]` and `string[]` in one program
gets two exact copies, no boxing. The dynamic / reflective / name-addressed entry
keeps the single erased copy as the fallback. Design:
`docs/design/monomorphization.md`, `docs/design/monomorphize-callable-dim.md`.

## Cost summary

| Form | Bodies | Type variable | Boxing |
|------|--------|---------------|--------|
| Erased class (`@template`) | one shared | tagged cell | per erased value |
| Bounded (`T of C`) | one shared | `obj<C>` raw ptr | none for the receiver |
| Generic trait (`@use T<X>`) | copied per class | substituted → concrete | **none** |
| Reified (`@var C<X> = new C`) | one per binding | concrete field/body | **none** (thunks bridge erased use) |
| Monomorphization | one per call shape | concrete param | **none** on the fast path |

## Limitations

- `@var C<X>` reifies only where the site **constructs** the value; a bare `C $x`
  handed in from elsewhere stays erased (the soundness rule above).
- The type variable of an **erased** class is a cell — correctness is guaranteed,
  but boxing cost remains; reach for a trait or a reified `@var … = new …` when the
  hot path needs it.
- Generics are docblock-driven; there is no native `<T>` syntax (a future
  parser-only addition would feed the same lowering engine unchanged).
