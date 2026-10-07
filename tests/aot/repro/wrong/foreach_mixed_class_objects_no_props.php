<?php
// foreach over an object taken from an array of objects of different classes yields none of its properties.
// issue: #122
final class P { public int $a = 1; }
foreach ([new P(), (object) ['x' => 1]] as $o) {
    foreach ($o as $k => $v) { echo $k, '=', $v, "\n"; }
}
echo "end\n";
