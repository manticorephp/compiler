<?php
// implode_int — implode over vec[int] and vec[float].
// Today: full boxToCell rebuild + tagged_to_str twice per element.
$ints = [];
for ($i = 0; $i < 2000; $i++) { $ints[] = $i * 7 - 3; }
$fls = [];
for ($i = 0; $i < 2000; $i++) { $fls[] = $i + 0.5; }
$acc = 0;
$reps = 500 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $acc += strlen(implode(",", $ints));
    $acc += strlen(implode(",", $fls));
}
echo $acc, "\n";
