<?php

// A static-property stack holding CALLABLES, pushed and popped, then invoked
// after the pop — the storage shape the error/exception/shutdown handlers need.
// Every prelude static to date has held an int, so this is the load-bearing
// question: does a closure / array-callable / string-callable survive a round
// trip through a class-static array cell?

class Reg
{
    /** @var array<int,mixed> */
    public static array $stack = [];

    public static function push(mixed $cb): mixed
    {
        $n = \count(self::$stack);
        $prev = $n > 0 ? self::$stack[$n - 1] : null;
        self::$stack[] = $cb;
        return $prev;
    }

    public static function top(): mixed
    {
        $n = \count(self::$stack);
        return $n > 0 ? self::$stack[$n - 1] : null;
    }

    public static function pop(): void
    {
        \array_pop(self::$stack);
    }
}

class Greeter
{
    public string $who = "world";
    public function hi(string $s): string { return "hi " . $s . " from " . $this->who; }
}

function shout(string $s): string { return \strtoupper($s); }

/** Invoke any callable shape with one argument. */
function call1(mixed $cb, string $arg): string
{
    if (\is_array($cb)) {
        $o = $cb[0];
        $m = $cb[1];
        return $o->$m($arg);
    }
    return $cb($arg);
}

$g = new Greeter();

Reg::push(fn(string $s): string => "closure:" . $s);
echo call1(Reg::top(), "a"), "\n";

Reg::push([$g, "hi"]);
echo call1(Reg::top(), "b"), "\n";

Reg::push("shout");
echo call1(Reg::top(), "c"), "\n";

Reg::push("strrev");
echo call1(Reg::top(), "abc"), "\n";

echo \count(Reg::$stack), "\n";

Reg::pop();
echo call1(Reg::top(), "d"), "\n";
Reg::pop();
echo call1(Reg::top(), "e"), "\n";
Reg::pop();
echo call1(Reg::top(), "f"), "\n";
Reg::pop();
echo Reg::top() === null ? "empty\n" : "NOT EMPTY\n";
