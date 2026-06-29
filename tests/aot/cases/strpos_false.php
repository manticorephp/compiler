<?php
$p = strpos("hello world", "world");
if ($p === false) { echo "miss\n"; } else { echo "hit\n"; }
$q = strpos("hello", "xyz");
if ($q === false) { echo "miss\n"; } else { echo "hit\n"; }
echo ($q !== false) ? "found\n" : "none\n";
