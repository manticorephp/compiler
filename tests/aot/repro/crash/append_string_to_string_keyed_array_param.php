<?php
// Appending a string to a string-keyed int array param (mixed int|string keys) then print_r crashes.
// issue: #259
function f(array $a): array { $a[] = 'tail'; return $a; }
print_r(f(['x' => 1, 'y' => 2]));
