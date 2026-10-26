# Differences from PHP

Manticore's goal is that a program prints the same bytes as `php`. This page is the
honest list of where it does not: what a compiled program does differently, why, and
what to write instead. Read it before porting an application; the table below is the
scan, the sections explain.

Everything here was run side by side under `php` 8.5.11 and `manticore` 0.16.0 (macOS
arm64), unless it cites a document instead. Three kinds of entry are mixed on purpose:

- **by design** — the difference is the point of an AOT compiler (no `eval`, no `php.ini`);
- **known gap** — documented debt, tracked in [gaps.md](gaps.md);
- **bug** — php is right and Manticore is wrong. Listed so you do not lose a day to
  it; they close over time. See [Known wrong answers](#known-wrong-answers).

What Manticore **adds** on top of PHP (async, FFI, `Ds`, `Http\`, …) is the opposite
list: [superset.md](superset.md). Coverage of PHP's own functions and classes, name by
name: [builtins.md](builtins.md).

## The quick list

| Area | php | Manticore | Kind |
|---|---|---|---|
| `"12"` passed to an `int` / `float` parameter, property or return | converted (weak mode) | **not converted** — the value is garbage ([why](#declared-scalar-types-are-trusted-not-enforced)) | bug / gap |
| Reading an undefined variable | warning, `null` | compile error ([details](#undefined-variables)) | by design |
| A local assigned in only one branch, then `isset()` | `false` when the branch did not run | `true` | gap |
| `PHP_INT_MAX + 1` computed at run time | `float` | wraps to `PHP_INT_MIN` ([details](#integer-overflow-wraps)) | known gap |
| Undefined array key / property / `null[...]` read | warning, `null` | **silent**; the value is `null` or a zero of the inferred type ([details](#warnings-and-notices)) | by design / gap |
| Other engine warnings and notices | printed, execution continues | not printed; some become exceptions | by design |
| `set_error_handler` | receives engine warnings | receives only `trigger_error()` | by design |
| `private` / `protected` access from outside | `Error` | `Error` when the receiver's class is known at compile time; not checked through erased receivers or inside closures ([details](#visibility-is-enforced-where-the-class-is-known)) | partial gap |
| `eval()` | works | not supported ([details](#features-that-need-the-interpreter)) | by design |
| Variable variables `$$n`, `${'n'}` | work | parse error | gap |
| `get_defined_vars()` | works | not defined | gap |
| `resource` type | a primitive type | a real class `\Resource` ([details](#resources-are-objects)) | by design |
| `var_dump($obj)` handle `#N`, `spl_object_id()` | 1, 2, 3 … | every object `#1`; `spl_object_id()` is an address ([details](#object-identity-and-output)) | gap |
| Uncaught exception message | message, trace, `thrown in` | one `PHP Fatal error: Uncaught …` line ([details](#uncaught-exceptions-and-error-messages)) | gap |
| Line of an exception raised by the runtime | the call site | `0` | gap |
| `php.ini`, `ini_set('memory_limit')`, extensions, PECL | yes | none; the manifest and FFI replace them ([details](#configuration)) | by design |
| Default time zone | `UTC` | the host's zone | by design |
| A function Manticore does not ship | n/a | compiles, traps at run time with `Call to undefined function` ([details](#functions-that-do-not-exist-yet)) | by design |
| `"10" + "5.5"` | `15.5` | `15` ([#403](https://github.com/manticorephp/compiler/issues/403)) | bug |

---

## Language and types

Manticore infers types and then *uses* them: for layout, for unboxing, for
monomorphization ([superset.md §5](superset.md#5-types-docsgenericsmd-docsdesigntype-system-v2md)). Most
PHP code never notices. The places where the static view leaks through are these.

### Declared scalar types are trusted, not enforced

php, in its default weak mode, converts a numeric string handed to an `int` or `float`
parameter; a value it cannot convert throws `TypeError`:

```php
function f(int $x) { return $x + 1; }
$parts = explode(",", "12,1.5,abc");
var_dump(f($parts[0]));      // php: int(13)    Manticore: int(4346303057)
var_dump(f($parts[2]));      // php: TypeError  Manticore: int(4346303217)
```

Manticore does **not** convert at the call boundary: a `string` reaching an `int` or
`float` parameter, typed property or return type is read as if it were already that
type, so what you get is the string's address. The same holds for a typed property
(`$t->n = "42"`) and a return (`function r(): int { return "7"; }`). Values the
compiler can see are the wrong type *and* can never convert are rejected: `array`,
`null` and objects into an `int` parameter throw `TypeError`, and with
`declare(strict_types=1)` a literal mismatch is reported at compile time. A `bool`, an
`int` into `float`, and an `int` into `string` convert correctly.

**What to do:** cast where text becomes a number — `f((int) $parts[0])` — and keep
request/CSV/`$argv` strings out of typed numeric parameters. This is the single most
likely source of silent wrong answers when porting code that relied on weak mode ([#402](https://github.com/manticorephp/compiler/issues/402)).

### Undefined variables

```php
function g() { return $nope ?? 'dflt'; }   // php: 'dflt'
```

php reads an undefined variable as `null` with a warning. Manticore resolves every
variable at compile time: reading one that is never assigned in the function stops the
build with `MIR.verify: dangling local $nope read in g but never defined`, even under
`??`. Declare it (`$nope = null;`) first.

A variable assigned in only one branch is the opposite hazard — it compiles, but it
exists afterwards with a zero value, so `isset($v)` is `true` where php says `false`:

```php
function f($c) { if ($c) { $v = 1; } return isset($v) ? 'set' : 'unset'; }
echo f(true), f(false);     // php: setunset     Manticore: setset
```

Initialise to `null` before the branch and test `$v !== null`.

### Integer overflow wraps

```php
$a = PHP_INT_MAX; $a++;            var_dump($a);   // php: float(9.2233720368547758E+18)   here: int(-9223372036854775808)
function f(int $x) { return $x * 3037000500; }
var_dump(f(3037000500));                           // php: float(9.22337203700025E+18)     here: int(-9223372036709301616)
var_dump(2 ** 63);                                 // php: float(9.2233720368547758E+18)   here: int(-9223372036854775808)
```

Arithmetic on values the compiler types as `int` is two's-complement. Constant
expressions that overflow (`PHP_INT_MAX + 1` written out) *are* folded to `float`;
the difference shows in code the compiler types as `int` (a loop counter, a typed
parameter). Promoting to float needs value-range analysis and is a [known gap](gaps.md#tier-1--correctness) and a
[roadmap item](ROADMAP.md). If your code can legitimately overflow (hash mixing,
factorials, `PHP_INT_MAX` sentinels), use `bcmath`, `float`, or check with
`intdiv`/comparisons before the operation.

### Visibility is enforced where the class is known

```php
class P { private $secret = 's'; protected function hid() { return 'h'; } }
try { (new P)->secret; } catch (Error $e) { echo $e->getMessage(); }
// both: Cannot access private property P::$secret
```

Member access, static calls, class constants and `new` are checked against the enclosing
class when the compiler knows the receiver's class, and a denied access throws php's `Error`
with php's message. `__get`, `__set`, `__call` and `__callStatic` still fire for an inaccessible
member, and `Closure::bind`, reflection and the hierarchy rules (`parent::`, `self::`, `static::`,
protected access between siblings) behave as in php.

Not checked, so a violation still runs: access through an erased, `mixed` or union-typed receiver,
a variable member name (`$o->$name`), and anything inside a closure. A typed property without a
default that is read before it is set answers the zero of its type, where php throws
`Typed property A::$x must not be accessed before initialization`.

Class declarations php refuses at compile time are refused too: extending a `final` class,
overriding a `final` method or constant, leaving an abstract method unimplemented, and
weakening a member's visibility. Method-signature compatibility (LSP) is not checked yet.

### Features that need the interpreter

| Feature | Behaviour |
|---|---|
| `eval()` | not supported. The build prints `undefined-function traps (1): eval`; at run time `Call to undefined function eval()` |
| variable variables (`$$n = 5`, `${'n'}`) | parse error |
| `get_defined_vars()` | not defined (trap at run time) |
| `goto` into a loop body | unsupported (forward/backward `goto` works) |
| `ReflectionEnum` | does not exist; the rest of Reflection ships |

What works and might be assumed not to: `include` / `require` with a path held in a
variable (checked with a file written at run time), `new $className`, `$fn()`,
`call_user_func*`, `compact()`, `extract()`, `settype()`, `$GLOBALS['x']`, `global`,
`static`, references, `__get`/`__call`, dynamic properties.

### Known wrong answers

These are php-correct, Manticore-wrong. Each has (or is getting) a minimal repro under
`tests/aot/repro/`; they are bugs, not policy, so expect them to close.

| Snippet | php | Manticore |
|---|---|---|
| `var_dump("10" + "5.5");` and `$p = explode(",", "5,3.5"); $p[0] + $p[1]` | `float(15.5)`, `float(8.5)` | `int(15)`, `int(8)` — a fractional numeric string is truncated in arithmetic. `"9" ** 2` is `float(81)` where php says `int(81)`  ([#403](https://github.com/manticorephp/compiler/issues/403)) |
| `is_callable('strlen')`, `is_callable('strtoupper')` | `true` | `false` for functions Manticore inlines as codegen builtins (calling them by name, `array_map('strtoupper', …)`, works; `array_map` is `true`)  ([#323](https://github.com/manticorephp/compiler/issues/323)) |
| `class_exists('DateTime')`, `class_exists($name)` for a built-in class the program never names | `true` | `false` — built-in classes are linked on demand ([superset.md, rule 3](superset.md#the-rules-the-superset-lives-under)); naming the class anywhere in the program (`new ArrayObject`) is enough. Your own classes are always found  ([#409](https://github.com/manticorephp/compiler/issues/409)) |
| `include $f; include_once $f;` | second returns `true` | executes the file again  ([#407](https://github.com/manticorephp/compiler/issues/407)) |

---

## Warnings and notices

The rule in [AGENTS.md](../AGENTS.md#design-principles) is: **where Zend emits a warning
and carries on, Manticore throws.** It is not emulating the warning channel. In
practice there are three behaviours, and which one you get depends on the operation:

| php | Manticore |
|---|---|
| Throws already (`TypeError`, `ValueError`, `DivisionByZeroError`, `ArgumentCountError`, `Error: Call to a member function …`) | the same exception. Verified: `intdiv(1,0)`, `1 % 0`, `str_repeat('x', -1)`, `array_combine([1],[1,2])`, undefined constants, too few arguments |
| Warns, returns a value (`hex2bin("zz")`, `array_sum([1,"a"])`) | throws where the stdlib was written to: `hex2bin()` raises `ValueError`, `array_sum()` raises `TypeError: Unsupported operand types: int + string`. Code that tested for `false` after the warning must catch instead |
| Warns on a *read* of something missing (undefined array key, undefined property, array offset on `null`, uninitialized string offset, array to string conversion, non-numeric value) | **neither** warns nor throws. The read yields `null` or a zero of the inferred element type |

```php
$a = [1]; var_dump($a[5]);              // php: Warning + NULL     Manticore: int(0)
$m = ['a' => 'x']; var_dump($m['q']);   // php: Warning + NULL     Manticore: NULL
$o = new stdClass; var_dump($o->nope);  // php: Warning + NULL     Manticore: float(0)
```

So an absent key does not announce itself and, in a statically-typed array, is not even
`null`. Use `isset()`, `??` or `array_key_exists()` — they are exact — and do not rely
on `=== null` after a bare read. `echo INF . NAN` prints the same text but without the
`unexpected NAN value` warning.

Consequences:

- **`set_error_handler()` only sees `trigger_error()`.** There are no engine warnings to
  hand it, so logging or test-harness code that converts warnings into exceptions has
  nothing to convert, and `@` has nothing to suppress. `error_reporting()` still answers `30719`.
- **`E_DEPRECATED` is not raised.** Creating a dynamic property (deprecated since 8.2) is silent.
- The static analyzer reports at *compile* time what php reports at run time:
  `bin/manticore compile` prints `warning:` / `error:` lines for a string in arithmetic,
  a bare `array` parameter, a call with the wrong arity. These do not stop the build
  (an `error:` such as `unknown method ArrayObject->uasort()` builds a binary that
  traps if the line is reached); a parse error or a failed MIR check does.

## Uncaught exceptions and error messages

```
php:         PHP Fatal error:  Uncaught RuntimeException: boom in /app/e3.php:2
             Stack trace:
             #0 /app/e3.php(3): f()
             #1 /app/e3.php(5): g()
             #2 {main}
               thrown in /app/e3.php on line 2
Manticore:   PHP Fatal error:  Uncaught RuntimeException: boom
```

The process exits with `255` in both. `getTraceAsString()`, `getFile()`, `getLine()` and
`getCode()` of a *caught* exception match php for exceptions you throw. Two differences
inside `catch`:

- an exception raised by the runtime or stdlib (`intdiv(1, 0)`, a `TypeError`) has
  `getLine() === 0` where php reports the call site;
- the **text** of some engine errors differs: calling a method on `null` is
  `Call to undefined method object::m()` here, `Call to a member function m() on null`
  in php. Match on the class, not on the message.

Anything that parses php's fatal-error text (a supervisor, a log shipper) needs a
look.

## Object identity and output

`var_dump`, `print_r`, `var_export`, `json_encode`, `serialize`, float printing and
`printf` match php byte for byte over the corpus (`tools/difftest.sh`), including enums
and nested objects. The exceptions:

- **Object handles.** `var_dump($a, $b)` prints `#1` for both objects, where php counts
  `#1`, `#2`; and `spl_object_id()` returns a large address rather than a small
  counter. `spl_object_hash()` and `SplObjectStorage` are consistent and identity-correct;
  only the *number* differs, so never print or persist it.
- **Closures** dump as `object(Closure)#1 (0) {}`; php 8.5 adds `name` / `file` / `line`.
- Private/protected keys of `(array) $obj` are not mangled (`"h"`, not `"\0P\0h"`):
  [gaps.md](gaps.md#magic-methods--what-is-knowingly-not-done).
- A bare `array` property or parameter hint erases the element type; write
  `int[]` / `array<string,Foo>` in a docblock ([AGENTS.md](../AGENTS.md#invariants-that-are-easy-to-break))
  or `var_dump` can print ints wrongly.

## Resources are objects

php has a primitive `resource` type. Manticore has a class, `\Resource`
(`prelude/resource.php`), in the global namespace: `fopen`, `opendir`, `curl_init`,
`stream_socket_*` and the rest of the stream family return one. php is moving its own
resources to objects the same way (`GdImage`, `CurlHandle`).

```php
$f = fopen('php://memory', 'r+');
var_dump(is_resource($f), gettype($f));   // both: bool(true) string(8) "resource"
var_dump($f);                             // both: resource(N) of type (stream) — N differs: php 5, here 1
var_dump(is_object($f), $f instanceof Resource, get_class($f));
// php:        bool(false) bool(false) TypeError (get_class() wants an object)
// Manticore:  bool(true)  bool(true)  string(8) "Resource"
```

What this means for a port:

- `is_resource()`, `gettype()`, `get_resource_type()`, `get_resource_id()`, `var_dump`,
  `print_r` and casting to `(int)` / `(bool)` behave as php. Test with `is_resource()`.
- `is_object($r)` is **true** and `$r instanceof \Resource` is true. Code that branches
  `is_object($x) ? … : (is_resource($x) ? … : …)` takes the object branch for a
  stream; test `is_resource()` first.
- `get_resource_id()` counts from `1`, not from php's startup-dependent number.
- **`Resource` is a reserved global class name.** `class Resource {}` in the global
  namespace fails the build (`invalid redefinition of function 'manticore_Resource___…'`).
  In a namespace (`App\Resource`) it is fine, and `$f instanceof Resource` there means
  `App\Resource`. The other global names the prelude owns are `FdPollHandle`,
  `FiberExit`, `StreamPollHandle` (full list: [builtins.md, global names](builtins.md#global-names-that-are-not-phps)).
- A dropped, unclosed `\Resource` closes its descriptor in `__destruct`, as php does at
  zero refcount.

## Configuration

There is no `php.ini`, no `-d` flag, no extension loader, no PECL
([superset.md, rule 4](superset.md#the-rules-the-superset-lives-under)).

- `ini_get()` / `ini_set()` exist and keep values for the keys programs commonly read
  (`precision`, …), but there is no file to read defaults from. They differ from php's:
  `memory_limit` is `-1` here (php CLI: `128M`), `display_errors` is `1`,
  `php_ini_loaded_file()` is `false`.
  A program is configured by its manifest, its code and the few
  [`MANTICORE_*` variables](superset.md#8-runtime-knobs).
- **`date_default_timezone_get()` follows the host's zone**; php without a `date.timezone`
  setting says `UTC`. Call `date_default_timezone_set()` in programs whose output
  depends on it. On Alpine install `tzdata` ([install.md](install.md)).
- `extension_loaded()` answers for what is *linked in*: `true` for `mbstring`, `intl`,
  `json`, …; `false` for `gmp`, `gd`, `Zend OPcache`.
  `function_exists('gmp_add')` is the right guard for optional extensions.
- `php_sapi_name()` is `cli`; under `Http\Server` the request state is per fiber
  ([http.md](http.md)). `$argv`, `$argc`, `$_SERVER['argv']`, `getenv()`, `STDIN`
  work as in the CLI.
- Native libraries come in as FFI bindings or manifest targets ([ffi.md](ffi.md),
  [modules.md](modules.md)), not as extensions.
- `Zend OPcache` and `xdebug` are not present (`opcache_get_status` does not exist;
  `opcache_invalidate` / `opcache_compile_file` are stubs, see [builtins.md](builtins.md#global-names-that-are-not-phps)).

## Functions that do not exist yet

Roughly half of PHP's internal functions and a third of its classes are not
implemented (`gd`, `gmp`, `sodium`, `ftp`, `xmlwriter`, most of `pgsql`, `posix`,
`calendar`, `ldap`, `bz2`, …; 1127 of 2135 functions, 206 of 327 classes at the time of
writing). The full, generated, per-extension list is [builtins.md](builtins.md).

A missing function does **not** fail the build. The compile prints a note and the call
traps if reached:

```
build: undefined-function traps (1): imagecreate
…
PHP Fatal error:  Uncaught Error: Call to undefined function imagecreate()
```

The behaviour is the same as php's for a function that is not installed, so
`function_exists()` / `extension_loaded()` guards keep working
(`function_exists('gmp_add')` is `false` here). Read the `undefined-function traps` line of
your build; it is the porting checklist. `ArrayObject::uasort()` is an example at class
level: a missing method compiles with an `error: unknown method` line and traps at run
time.

## Platforms

php is the same everywhere; Manticore is not, and **Linux comes first**: the Linux gate
is what a release must pass, macOS is the development host
([install.md](install.md#platform-support)).

- Where the C library answers differently, the answer is the *host's* php, not glibc's:
  on musl/Alpine `iconv` has no `//TRANSLIT` or `//IGNORE`, and php there returns
  `false` for both, as does a Manticore binary ([install.md](install.md#alpine-musl)).
  Some `glob` constants are absent on musl.
- Memory per fiber, address-space limits and `epoll` vs `kqueue` behaviour differ and
  are measured in [async.md](async.md); the async engine and syscall layer are only
  trusted once green on Linux.
- Windows is not supported ([ROADMAP.md](ROADMAP.md)).

## Memory and lifetimes

The memory model is refcounting plus escape-routed arenas
([memory.md](memory.md)), not php's refcount-plus-GC. The visible differences:

- **Cycles leak** unless the program references `gc_collect_cycles()`; once it does the
  collector also runs automatically. Zend collects cycles with no help.
- Destructor order at one scope exit can differ from php's declaration order when one of
  the objects sits in a reference box ([gaps.md](gaps.md#tier-1--correctness)).
- `memory_get_usage()` and `memory_get_peak_usage()` measure this allocator, not
  Zend's; absolute numbers are not comparable.
- Static properties are process-global symbols; two compilation units cannot disagree
  about one.

---

## Porting checklist

1. Build and read the `undefined-function traps` and `warning:` / `error:` lines.
2. Search for `eval(`, `$$`, `get_defined_vars(`, `class Resource`.
3. Cast strings to numbers where they enter typed parameters, properties and returns.
4. Replace bare reads of possibly-missing keys with `??` / `isset()` / `array_key_exists()`.
5. Do not branch on `is_object()` before `is_resource()`; do not print or store object ids.
6. Run your tests with `bin/manticore compile` output — anything that asserted on a php
   warning, a fatal-error text or `set_error_handler` for engine warnings needs a rewrite.
7. Check `docs/gaps.md` for the corner you rely on (magic methods, references, `(array)`).
