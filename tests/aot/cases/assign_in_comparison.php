<?php
function foo(): int { return 5; }
if (false === $v = foo()) { echo "eqfalse\n"; } else { echo "v=", $v, "\n"; }
if (1 > $n = 0) { echo "nsmall=", $n, "\n"; }
$a = 'x';
if ('x' === $b = $a) { echo "match=", $b, "\n"; }
$len = 0;
if (false !== $len = strlen("abcd")) { echo "len=", $len, "\n"; }
