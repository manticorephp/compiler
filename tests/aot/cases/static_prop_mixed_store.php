<?php

// A CELL-declared static property is read BY TAG, so every store into it has to
// NaN-box. Storing raw made each scalar come back as a denormal float, `=== 42`
// false for a slot just assigned 42, and — the one that cost a SIGSEGV — a
// `= null` assignment left the slot reading as NOT null, so an optional-callback
// guard called through it. (The INITIALISER half of this was fixed earlier; this
// is the assignment half.)

final class Slot
{
    public static mixed $v = 0;

    public static function set(mixed $x): void { self::$v = $x; }
    public static function clear(): void { self::$v = null; }
    public static function get(): mixed { return self::$v; }
}

Slot::$v = 42;
var_dump(Slot::$v);
var_dump(Slot::$v === 42);

Slot::$v = 'text';
var_dump(Slot::$v);
var_dump(Slot::$v === 'text');

Slot::$v = true;
var_dump(Slot::$v);
var_dump(Slot::$v === true);

Slot::$v = 1.5;
var_dump(Slot::$v);
var_dump(Slot::$v === 1.5);

Slot::$v = null;
var_dump(Slot::$v);
var_dump(Slot::$v === null);
var_dump(Slot::$v !== null);

// The same through a method that assigns via self:: — the shape a runtime hook
// registry uses (install/clear).
Slot::set(7);
var_dump(Slot::get() === 7);
Slot::clear();
var_dump(Slot::get() === null);

// A callable parked in the slot and cleared again: the null must be visible, or
// the guard below calls through a null.
Slot::set(function (int $x): int { return $x * 2; });
$fn = Slot::get();
var_dump($fn !== null);
var_dump($fn(21));
Slot::clear();
$fn2 = Slot::get();
var_dump($fn2 === null);
echo $fn2 === null ? "guard holds\n" : "guard broken\n";

// A large int is the sharp probe for a raw/tagged mixup — a raw value read as a
// tag comes back as a denormal, a boxed one read raw comes back huge. 2^40 fits
// the 48-bit payload. (⚠ At 2^50 `=== $literal` is false in EVERY cell slot —
// a mixed param, a mixed instance prop, this one — while var_dump still prints
// the right number: a general cell-compare limit, not this store's.)
Slot::$v = 1099511627776;
var_dump(Slot::$v);
var_dump(Slot::$v === 1099511627776);
