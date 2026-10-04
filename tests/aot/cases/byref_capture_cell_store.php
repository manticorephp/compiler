<?php
// A by-ref capture whose two frames store different kinds is one CELL word:
// every store through the reference box has to box, or a reader decodes the
// raw word by tag (`null` read back as float(0), `new X` as garbage).

function neverCalled(): void {
    $exception = null;
    $f = static function () use (&$exception) { $exception = new \RuntimeException('x'); };
    var_dump($exception);
}

function thrownFromClosure(): string {
    $exception = null;
    $f = static function (string $m) use (&$exception): void {
        $exception = new \RuntimeException($m);
    };
    $f('boom');
    if ($exception !== null) {
        try { throw $exception; } catch (\RuntimeException $e) { return $e->getMessage(); }
    }
    return 'none';
}

function intStore(): void {
    $e = null;
    $f = static function () use (&$e) { $e = 5; };
    var_dump($e);
    $f();
    var_dump($e);
}

function strStore(): void {
    $e = null;
    $f = function () use (&$e) { $e = 'str'; };
    $f();
    var_dump($e);
}

function floatAfterMixed(int $n): void {
    $v = 1;
    if ($n > 0) { $v = 'x'; }
    $f = function () use (&$v) { $v = 2.5; };
    var_dump($v);
    $f();
    var_dump($v);
}

function outerStoresAfterCapture(): void {
    $n = null;
    $f = function () use (&$n) { var_dump($n); };
    $f();
    $n = 3;
    $f();
    $n = 'three';
    $f();
}

function objectAlias(): void {
    $o = new \ArrayObject([1, 2]);
    $held = null;
    $f = function () use (&$held, $o) { $held = $o; };
    $f();
    unset($o);
    var_dump(count($held));
}

neverCalled();
echo thrownFromClosure(), "\n";
intStore();
strStore();
floatAfterMixed(1);
outerStoresAfterCapture();
objectAlias();
