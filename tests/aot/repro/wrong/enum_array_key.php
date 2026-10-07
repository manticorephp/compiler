<?php
// An enum used as an array key does not throw TypeError
// issue: #37
enum E: string { case A = 'a'; }
$k = E::A;
$a = [];
try { $a[$k] = 1; echo "no error\n"; } catch (\TypeError $e) { echo "TypeError\n"; }
