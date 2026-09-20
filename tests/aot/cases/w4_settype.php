<?php
$v = "123"; settype($v, "int"); var_dump($v);
$v = 70000; settype($v, "string"); var_dump($v);
$v = "1.5"; settype($v, "float"); var_dump($v);
$v = 0; settype($v, "bool"); var_dump($v);
$v = 5; settype($v, "array"); var_dump($v);
$v = "x"; settype($v, "null"); var_dump($v);
$m = 99999; $r = settype($m, "string"); var_dump($r, $m);
