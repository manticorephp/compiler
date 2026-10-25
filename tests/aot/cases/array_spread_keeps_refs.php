<?php
// Spreading an array of references into an array literal (`[...$refs]`) copies the values; php keeps the references.
$a = 1; $r = [&$a]; $c = [...$r]; $c[0] = 5; var_dump($a);
