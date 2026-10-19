<?php
// A temporary returned through a `T|false` method return and used in a call chain is not destructed at the end of the statement.
class S { function __destruct() { echo "dtor\n"; } function v(): int { return 1; } }
class D { function mk(): S|false { $s = new S(); return $s; } }
$d = new D();
$x = $d->mk()->v();
echo "after\n";
