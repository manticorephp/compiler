<?php
$f = new Fiber(function (int $x): int {
    $y = Fiber::suspend($x + 70000);
    return $y * 2;
});
var_dump($f->start(5));
var_dump($f->resume(70000));
var_dump($f->getReturn());
var_dump($f->isTerminated());
