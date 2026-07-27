# `#[Async]` — design note (not implemented)

Everything else in `Async\` is a library. This one cannot be: it needs the compiler to
rewrite a function body and to type the call site as a task of the function's return type.
This note is what the implementation would be, written before any of it, so the decision is
made on the shape rather than on a half-built version of it.

## What it is for

```php
#[Async]
function fetchUser(int $id): User { … }

$a = fetchUser(1);          // starts running; type is Task<User>
$b = fetchUser(2);
[$x, $y] = Async\awaitAll($a, $b);
```

The same thing is already writable today:

```php
$a = Async\spawn(fn() => fetchUser(1));
```

so the attribute buys **exactly two things**: the call site reads like an ordinary call, and
`await()` comes back typed as `User` instead of `mixed`. That is a real ergonomic gain and
nothing more — it adds no capability. Worth remembering when weighing the cost below.

## The typing question is already answered

The docs used to say a generic `Task<T>` "has no way to express the binding". That is out of
date. `Type` carries `typeArgs` (`src/Compile/Mir/Type.php`), and
`InferCalls::genericReturnType()` resolves a method's `@template` return **from the
receiver's `typeArgs` alone** — no class specialization involved
(`LowerReify` reifies only when a program asks for a reified generic; the erased
`obj<Cls> + typeArgs` mode is a first-class path).

So:

- `Async\Task` gains `/** @template T */` and `await(): T`, `join(): Settled` unchanged.
- A call to an `#[Async]` function types as `Type::obj('Async\Task')` with
  `typeArgs = [<the function's declared return type>]`.
- `$t->await()` then infers as that type, with no reification and no runtime change: a
  `Task` is one class at runtime exactly as it is now.

A function with no declared return type yields `Task<mixed>` — today's behaviour, no worse.

## The rewrite

`#[Async] function f(P $p): R { BODY }` lowers to two functions:

```php
function f__body(P $p): R { BODY }            // untouched
function f(P $p): Task  { return Async\__spawnAt('<site>', 'f__body', $p); }
```

Splitting rather than wrapping the body in a closure in place matters: `BODY` keeps its own
frame, its `static` locals stay where the author put them (and the analyzer's
`async.static-local` rule still sees them), and a recursive `#[Async]` function calls the
async wrapper, which is what the author wrote.

Where it goes: a MIR pass after `LowerFns` and before `InferTypes` — the attribute is
already parsed and reachable (`BuiltinAttributes` + the canonical name resolver the
reserved-attributes epic built), and the site literal comes from the same lowering hook that
rewrites `Async\spawn` today (`LowerExprs::asyncSiteCallee`).

## The three things that need deciding

1. **Called outside `async()`.** `spawn()` throws `LogicException('spawn() outside
   Async\async()')`. For an explicitly-`Async` function that error is *right* but arrives
   late — at the first call, at runtime. The alternative (run the body synchronously and
   return a settled Task) is worse: it silently changes concurrency with the calling
   context, which is the thing this whole runtime refuses to do everywhere else.
   **Recommendation: keep the throw**, and have the analyzer report a call to an `#[Async]`
   function from a file that never mentions `Async\` — a compile-time answer to the common
   case.

2. **`#[Async]` on a method.** Same rewrite, but the split body needs `$this`, and an
   `#[Async]` method on an interface/parent has to stay compatible with an override that
   lacks the attribute. **Recommendation: functions and FINAL/private methods in the first
   cut**; reject the attribute elsewhere with a clear message rather than shipping a
   half-answer to inheritance.

3. **The return type is a lie to a reader.** The source says `: R`, the call site produces
   `Task<R>`. PHP has no syntax to say otherwise, and every other language with this feature
   does the same. `ReflectionFunction::getReturnType()` should keep reporting `R` (it is
   what the author wrote); the attribute is what tells you it is a task. Worth one line in
   the docs, not a mechanism.

## Cost, honestly

The typing is cheap now, and the rewrite is a contained pass. What is not cheap: `#[Async]`
becomes a second way to spell concurrency, so every diagnostic that names a task
(`dump()`, the watchdog, deadlock reports) has to name these too — they will, since they all
go through `spawn`'s site plumbing — and every future scheduler change has to keep two entry
shapes working. That is the real reason to do it *after* the runtime settles rather than
during, and to keep the first cut narrow.

## Verification the implementation would need

- A php-oracle test is impossible (php has no `#[Async]`), so: manticore-only cases for the
  call site typing (`await()` result used where `R` is required, no cast), the outside-
  `async()` throw, recursion, and `dump()` showing the function's own file:line as the
  task's origin.
- `dump-mir` on a program with no `#[Async]` must be byte-identical — the pass has to be a
  no-op when the attribute is absent.
- The existing async suite, unchanged, over two generations.
