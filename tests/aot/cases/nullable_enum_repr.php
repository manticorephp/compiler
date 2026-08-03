<?php

// `?Enum` is not a nullable pointer. An enum case is an ORDINAL, so the FIRST
// case's carrier is 0 — the same word a null object uses — and a `?Enum` slot
// that lowered to a bare `obj<Enum>` could not tell them apart: `$m === null`
// answered true for `Method::Get`, and a real null handed to such a parameter
// was unboxed as an ordinal, dereferencing the NULL tag's bits (SIGSEGV).
// A `?Enum` now lowers to a cell, which is what the `Enum|null` spelling has
// always done. php is the oracle for every line here.

enum M: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';

    public function isSafe(): bool { return $this === M::Get; }
    public function label(): string { return $this->name . '/' . $this->value; }
}

enum Pure
{
    case A;
    case B;
    public function isB(): bool { return $this === Pure::B; }
}

function viaParam(?M $m): string { return $m === null ? 'NULL' : $m->name; }
function viaValue(?M $m): string { return $m === null ? 'NULL' : $m->value; }
function viaMethod(?M $m): string
{
    if ($m === null) {
        return 'NULL';
    }
    return $m->isSafe() ? 'safe' : 'unsafe';
}
function viaUnion(M|null $m): string { return $m === null ? 'NULL' : $m->label(); }
function make(string $s): ?M { return M::tryFrom($s); }
function pureParam(?Pure $p): string { return $p === null ? 'NULL' : ($p->isB() ? 'B' : 'A'); }

// The first case is the one the old ordinal-0 collision broke.
echo viaParam(M::Get), ' ', viaParam(M::Post), ' ', viaParam(null), "\n";
echo viaValue(M::Get), ' ', viaValue(M::Put), ' ', viaValue(null), "\n";
echo viaMethod(M::Get), ' ', viaMethod(M::Post), ' ', viaMethod(null), "\n";
echo viaUnion(M::Get), ' ', viaUnion(null), "\n";
echo pureParam(Pure::A), ' ', pureParam(Pure::B), ' ', pureParam(null), "\n";

// tryFrom straight into a ?Enum parameter — a miss used to segfault there.
echo viaParam(M::tryFrom('POST')), ' ', viaParam(M::tryFrom('NOPE')), "\n";
echo viaMethod(M::tryFrom('GET')), ' ', viaMethod(M::tryFrom('NOPE')), "\n";

// …and out of a ?Enum RETURN.
$hit = make('PUT');
$miss = make('NOPE');
echo ($hit === null ? 'NULL' : $hit->name), ' ', ($miss === null ? 'NULL' : $miss->name), "\n";
echo ($hit !== null ? 'notnull' : 'isnull'), ' ', ($miss !== null ? 'notnull' : 'isnull'), "\n";

// Identity and `match` over a cell-carried case.
$m = make('POST');
echo ($m === M::Post ? 'same' : 'diff'), ' ', ($m === M::Get ? 'same' : 'diff'), "\n";
echo match ($m) { M::Get => 'g', M::Post => 'p', M::Put => 'u', default => 'd' }, "\n";
$g = make('GET');
echo match ($g) { M::Get => 'g', M::Post => 'p', default => 'd' }, "\n";
echo match (make('NOPE')) { M::Get => 'g', M::Post => 'p', default => 'd' }, "\n";

// A readonly ?Enum property, promoted.
final class Holder
{
    public function __construct(public readonly ?M $m) {}
    public function show(): string { return $this->m === null ? 'NULL' : $this->m->name; }
}
echo (new Holder(M::Get))->show(), ' ', (new Holder(M::Put))->show(), ' ', (new Holder(null))->show(), "\n";

// A semi-reserved word is a legal class-constant name (php allows it because a
// constant is only ever reached through `Cls::NAME`).
final class Codes
{
    public const CONTINUE = 100;
    public const LIST = 1;
    public const PRINT = 2;
    public const DEFAULT = 3;
}
echo Codes::CONTINUE, ' ', Codes::LIST, ' ', Codes::PRINT, ' ', Codes::DEFAULT, "\n";

echo "done\n";
