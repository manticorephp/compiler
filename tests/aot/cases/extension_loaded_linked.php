<?php
// extension_loaded() answers true for every extension linked into the binary
// (WordPress refuses to boot without `hash`), false for one that is not.
foreach (['hash', 'json', 'date', 'spl', 'pcre', 'zlib', 'curl', 'filter', 'mysqli', 'no_such_ext'] as $e) {
    echo $e, '=', extension_loaded($e) ? 'y' : 'n', ' ';
}
echo "\n";
if (extension_loaded('hash')) { echo "guard folds\n"; }
