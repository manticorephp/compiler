<?php

// `Closure::bind($fn, $obj, Scope::class)` — the third argument decides what
// `self::` and `$this->` MEAN inside $fn. php resolves both against the BOUND
// SCOPE, not against the class the closure was written in; reaching another
// class's private members is the whole point of the idiom.
//
// symfony/http-foundation's RequestStack is the witness that stopped the tier-3
// build outright:
//
//     $reset ??= \Closure::bind(static fn () => self::$formats = null, null, Request::class);
//
// `self::$formats` is Request's static. Lowering read `self` from the lexical
// class, found nothing, and refused the whole program with
// "unsupported assign target kind StaticAccess".

class Request
{
    private static ?array $formats = ['html' => 1];
    private string $tag = 'req';

    public static function dumpFormats(): string { return self::$formats === null ? 'null' : 'set'; }
    public function tag(): string { return $this->tag; }
}

class RequestStack
{
    // RequestStack itself has NO $formats and no $tag — the offsets cannot come
    // from the enclosing class.
    private static ?array $formats = ['decoy' => 9];
    private string $tag = 'stack';

    public function resetFormats(): void
    {
        $f = \Closure::bind(static fn () => self::$formats = null, null, Request::class);
        $f();
    }

    public function readForeignInstanceProp(Request $r): string
    {
        $g = \Closure::bind(fn () => $this->tag, $r, Request::class);
        return $g();
    }

    public function ownFormats(): string { return self::$formats === null ? 'null' : 'set'; }
}

$s = new RequestStack();

echo Request::dumpFormats(), "\n";   // set
echo $s->ownFormats(), "\n";         // set

$s->resetFormats();

echo Request::dumpFormats(), "\n";   // null  -- the BOUND scope's static
echo $s->ownFormats(), "\n";         // set   -- the lexical one is untouched

// The instance half of the same rule.
echo $s->readForeignInstanceProp(new Request()), "\n";   // req

// A bind with NO scope argument still resolves lexically.
class Plain
{
    private static int $n = 5;
    public function get(): int { $f = \Closure::bind(static fn () => self::$n, null, self::class); return $f(); }
}

echo (new Plain())->get(), "\n";     // 5
