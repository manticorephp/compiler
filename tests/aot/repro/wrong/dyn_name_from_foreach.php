<?php
// A function name taken from a foreach over a string array calls the wrong arm: strlen(-5) gives float(0).
// issue: #304
foreach (["strlen"] as $n) { $f = $n; var_dump($f(-5)); }
