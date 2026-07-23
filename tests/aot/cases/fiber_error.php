<?php
try { Fiber::suspend(); } catch (\FiberError $e) { echo "1: " . $e->getMessage() . "\n"; }
$f = new Fiber(function(){ Fiber::suspend(); });
$f->start();
try { $f->start(); } catch (\FiberError $e) { echo "2: " . $e->getMessage() . "\n"; }
try { $f->getReturn(); } catch (\FiberError $e) { echo "3: " . $e->getMessage() . "\n"; }
$f->resume();
try { $f->resume(); } catch (\Error $e) { echo "4 (as Error): " . $e->getMessage() . "\n"; }
