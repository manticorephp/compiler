<?php
// Generator send slot is never cleared: next() after send() hands the sent value again
function g(): \Generator { $a = yield 1; var_dump($a); $b = yield 2; var_dump($b); }
$g = g();
$g->current();
$g->send('S');
$g->next();
