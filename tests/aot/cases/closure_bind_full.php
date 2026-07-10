<?php
class Box {
    private int $v = 42;
    public int $pub = 7;
}

// Closure::bind with explicit scope
$read = function(): int { return $this->v; };
$bound = Closure::bind($read, new Box(), Box::class);
echo $bound(), "\n";

// ->bindTo
$b2 = $read->bindTo(new Box(), Box::class);
echo $b2(), "\n";

// ->call (bind + invoke, with args)
$add = function(int $x): int { return $this->v + $x; };
echo $add->call(new Box(), 8), "\n";

// public property, scope via ::class
$rp = function() { return $this->pub; };
echo $rp->bindTo(new Box(), Box::class)(), "\n";

// Closure::fromCallable on a builtin
$up = Closure::fromCallable('strtoupper');
echo $up("hello"), "\n";

// Closure::fromCallable on a user function
function twice(int $n): int { return $n * 2; }
$t = Closure::fromCallable('twice');
echo $t(21), "\n";
