<?php
// A reference to a nested element of a mixed-key array (`$q = &$m['b'][0]; $q++`) reads back double bits.
$m = ['a' => 1, 'b' => [1, 2]];
$q = &$m['b'][0];
$q++;
echo $m['b'][0], "|", $q, "\n";
