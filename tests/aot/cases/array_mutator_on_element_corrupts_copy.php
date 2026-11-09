<?php
// array_shift / array_pop on an element ($a['q']) mutate the inner array in place, so a copy of the outer array and a reused literal change too
// issue: #601
function mk(int $n): array { return ['q' => range(1, $n)]; }
$a = mk(3);
$b = $a;
array_shift($a['q']);
array_pop($a['q']);
echo json_encode($a), ' ', json_encode($b), "\n";
for ($i = 0; $i < 3; $i++) { $el = ['q' => [1, 2, 3]]; echo array_shift($el['q']), ' '; }
echo "\n";
