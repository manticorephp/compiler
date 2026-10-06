<?php
// by-ref foreach: growing $v then reading/unsetting the element through the container is a use-after-free
// issue: #17
$a = ['x', 'y'];
foreach ($a as $k => &$v) {
    $v .= str_repeat('z', 100);
    echo strlen($a[$k]), "\n";
    unset($a[$k]);
}
unset($v);
echo count($a), "\n";
