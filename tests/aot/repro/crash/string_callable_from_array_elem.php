<?php
// A function-name string read from an array element and passed to a `callable` parameter SIGBUSes.
// issue: #252
function mixed0(): mixed { return 0.0; }
function show(callable $f): void { var_dump($f()); }
foreach (['mixed0'] as $n) { show($n); }
