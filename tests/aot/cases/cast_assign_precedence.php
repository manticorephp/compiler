<?php
$name = 'a:b';
if (0 < (int) $pos = strpos($name, ':')) { echo "first\n"; } else { echo "pos=$pos\n"; }
$n = 'x:y';
if (0 < (int) $q = strpos($n, ':')) { echo "q=$q\n"; } else { echo "none\n"; }
var_dump((string) $s = 5);
var_dump($s);
