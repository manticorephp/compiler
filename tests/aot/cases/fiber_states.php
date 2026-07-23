<?php
$f = new Fiber(function() {
    echo "A\n";
    Fiber::suspend();
    echo "B\n";
});
var_dump($f->isStarted(), $f->isTerminated());
$f->start();
var_dump($f->isStarted(), $f->isSuspended(), $f->isRunning());
$f->resume();
var_dump($f->isTerminated());
$cur = Fiber::getCurrent();
var_dump($cur === null);
