<?php

// An arrow function that ASSIGNS a name the enclosing scope does not have.
// php captures nothing there — the assignment creates the closure's own local —
// and reading it back sees what was just written. Capturing a NULL by value is
// the same thing, because an arrow fn captures by VALUE: the closure writes its
// own copy either way.
//
// symfony/var-exporter's ProxyHelper::exportDefault is the shape that found it:
//   fn ($m) => … ($parent = $class->getParentClass()) ? '\\'.$parent->name : 'parent'

final class Ancestor
{
    public string $name = 'the-parent';
}

final class Subject
{
    public function parentOrNull(bool $has): ?Ancestor
    {
        return $has ? new Ancestor() : null;
    }
}

function describe(Subject $s, bool $has): callable
{
    return static fn (string $tag): string =>
        ($parent = $s->parentOrNull($has)) ? $tag . ':\\' . $parent->name : $tag . ':none';
}

echo (describe(new Subject(), true))('a'), "\n";
echo (describe(new Subject(), false))('b'), "\n";

// The same name DOES exist outside: the capture is by value, so the closure's
// write must not be visible to the caller.
function shadowed(): string
{
    $seen = 'outer';
    $f = static fn (): string => ($seen = 'inner') . '/' . $seen;
    $r = $f();
    return $r . ' | outer still ' . $seen;
}
echo shadowed(), "\n";

// Read BEFORE the assignment, with nothing outside — php warns and yields the
// empty string; the value is what this pins.
function readFirst(): string
{
    $g = static fn (): string => '[' . ($u ?? 'unset') . ']' . ($u = 'now') . '[' . $u . ']';
    return $g();
}
echo readFirst(), "\n";

// A by-REF capture of an undefined name still creates it, and the write is
// visible to the enclosing frame.
function viaRef(): string
{
    $h = static function (string $m) use (&$err): void { $err = 'saw:' . $m; };
    $h('boom');
    return $err;
}
echo viaRef(), "\n";
