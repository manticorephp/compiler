<?php
// extension_loaded() answers for every extension the binary carries: the expression form and the guard form agree.
foreach (['session', 'pcre', 'json', 'mbstring', 'ctype', 'tokenizer', 'standard', 'core'] as $e) {
    echo $e, ': ', var_export(extension_loaded($e), true), "\n";
}
if (extension_loaded('session')) {
    echo "guard: session\n";
}
echo 'absent: ', var_export(extension_loaded('definitely_not_an_extension'), true), "\n";
