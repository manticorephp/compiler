<?php
// (array) on a statically-typed enum case segfaults instead of returning [name => ...]
enum E { case A; }
$a = (array) E::A;
echo count($a), "\n";
