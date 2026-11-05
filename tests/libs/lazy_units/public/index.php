<?php
// A src file's top-level code runs where it is required, not at startup.
echo "before require\n";
$r = require __DIR__ . '/../routes/web.php';
echo "after require ", json_encode($r), "\n";
var_dump(require __DIR__ . '/../routes/plain.php');
require __DIR__ . '/../routes/guarded.php';
echo "after guarded ", get_class(new Lazy\TokenPolyfill(1, "x")), "\n";
