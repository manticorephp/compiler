<?php
// A reference bound on one branch only (`if ($c) { $p = &$x; } $p = 5;`) aliases on every path: the false path writes $x too.
function t(bool $c): void { $x = 1; $p = 0; if ($c) { $p = &$x; } $p = 5; var_dump($x, $p); }
t(false); t(true);
