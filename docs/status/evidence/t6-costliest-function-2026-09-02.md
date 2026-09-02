# The +5 GB step, named — and a correction to how it was attributed

## ⚠ The earlier attribution was wrong, and the instrument caused it

The batch trace reported the FATTEST body per batch, so the batch that jumped
+5 017 MB was blamed on its fattest function, `PDOStatement::makeObject`, and a
"~280 bytes of RSS per byte of IR" build multiplier was inferred from it.

A stand with ONE deliberately fat function refutes that:

```
M=10    ir= 1.5 MB   delta=  9 MB   ratio=6
M=40    ir= 6.1 MB   delta= 30 MB   ratio=5
M=80    ir=12.2 MB   delta= 56 MB   ratio=4
M=160   ir=24.9 MB   delta=110 MB   ratio=4
```

A fat body costs **4-6x its own bytes, and the ratio FALLS with size**. There is
no 280x multiplier. **"Biggest by IR" and "costliest in memory" are different
questions**, and a batch holds 1024 functions — the fattest was merely nearby.

Fixed in the instrument rather than argued about: each function is now measured
with `memory_get_usage()` before and after emission (peak never falls, so the
delta is what that body added and never gave back), and the batch line reports
the max of BOTH:

```
emit batch 3072/4796  ir=41MB  fattest=322KB @2159 EmitLlvm__emitMethodCallInner
                              costliest=1MB @2708 LowerFromAst__preludeConstInt
```

Already on the self-build the two are different functions.

## The real culprit

```
47104 rss= 8953MB d=  +67  costliest=   6MB @47056 __mir_print_r_str
48128 rss=12555MB d=+3602  costliest=1876MB @47386 __mc_call_exception_handler
```

**One function, 1 876 MB.** It is `prelude/errors.php`:

```php
function __mc_call_exception_handler(mixed $cb, mixed $e): mixed
{
    if (\is_array($cb)) { $o = $cb[0]; $m = $cb[1]; return $o->$m($e); }
    return $cb($e);
}
```

`$o->$m($e)` — a dynamic method call on an ERASED receiver. Two siblings in the
same file have the same shape: `__mc_call_error_handler` (`$o->$m($level,
$message, $file, $line)`) and `__mc_call_shutdown_array` (`$o->$m(...$args)`).
These are PRELUDE functions, compiled into every program.

⚠ `memory_get_usage()` is the PEAK, so once a level is reached later batches
report a delta of 0. The attribution is therefore sound for the FIRST function
to reach a level and blind afterwards — the 0-delta rows after 48128 mean "the
peak did not grow", not "nothing happened".

## Why it is not already extracted

`dynamicMethodZeroArgHelper` exists and does exactly the right thing. Its gate:

```php
$canExtract = $iv->args === []
    && $recv->kind === Node::KIND_LOAD_LOCAL
    && (KIND_CELL | KIND_UNKNOWN | KIND_UNION)
    && \count($methods) >= 16;
```

**Zero-argument calls only** — and all three prelude sites pass arguments. Its
own doc already names the cost it was written to avoid: *"a small accessor
routine could become a 100+ MB function"*. The restriction is stated as
deliberate: "there are no by-ref/spread/default argument contracts to preserve"
in the zero-arg case.

## What extending it needs — and why it was NOT done here

The helper builds its call through `emitVirtualDispatch($objArg, 'i64 ' .
$objArg, …)`, so the mechanical part is small: extra `i64 %aN` parameters, an
argument list string, `methodTakesArgc(..., $argc)`, `vdSiteArgc = 1 + $argc`,
`faPushAny(..., 1 + $argc)`.

The part that is NOT mechanical is argument COERCION. The real call path passes
`$argOutTypes` and runs `vdArmArgs($argList, $argOutTypes, $this->sigs->
paramTypes[$sym], $sym)` per arm, because each target declares its own parameter
types and an erased site holds cells. The zero-arg helper passes `[]` and needs
none of it. Whether `erasedEntry`'s thunk unboxes ARGS as well as the receiver is
not established — `erasedEntry` only rewrites the symbol.

Guessing there produces a silent wrong answer of exactly the kind this branch has
already paid for twice (the `unser_object` bag retain; the borrowed sink-marker
path). So the extension is specified here and deliberately left unimplemented
rather than shipped unverified.

**Next step, precisely:** extend `dynamicMethodZeroArgHelper` to a fixed argc,
gated on no spread in the site args and no by-ref parameter among the candidate
methods, and reuse `vdArmArgs` with the site's `$argOutTypes` so per-target
coercion is preserved. Verify on the stand's `dyn_method` shape (325 B/class,
untouched), then the suite, then T6.
