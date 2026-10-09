<?php
// A dynamic property store and a foreach over an object from an array of objects of different classes SIGSEGVs.
// issue: #126
#[AllowDynamicProperties]
final class P { public int $a = 1; }
foreach ([new P(), (object) ['x' => 1]] as $o) {
    $o->{'z'} = 'zed';
    $n = 0;
    foreach ($o as $k => $v) {
        echo $k, '=', $v, "\n";
        if (++$n >= 10) { break; }
    }
    echo "count $n\n";
}
