<?php
function g(): \Generator { $x = yield 1; var_dump($x); $y = yield 2; var_dump($y); }
$gen = g(); $gen->current(); $gen->send('S'); $gen->next();
