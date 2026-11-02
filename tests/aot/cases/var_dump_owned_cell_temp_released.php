<?php
// An owned cell temp handed to var_dump (a short ternary over a mixed call) is released after the dump, not at exit.
class RC { public function __construct(public int $i = 0) {} public function __destruct() { echo "d{$this->i} "; } }
function ids(mixed $v): mixed { return $v; }
function show(mixed $v): void { echo is_object($v) ? 'o ' : 's '; }
show(ids(new RC(1)) ?: 'e'); echo "|\n";
$x = ids(new RC(2)) ?: 'e'; unset($x); echo "|\n";
var_dump(ids(new RC(3)) ?: 'e'); echo "|\n";
for ($i = 0; $i < 3; $i++) { show(ids(new RC(10 + $i)) ?: 'e'); } echo "|\n";
var_dump(ids(new RC(4)), spl_object_id(new RC(5))); echo "|
";
