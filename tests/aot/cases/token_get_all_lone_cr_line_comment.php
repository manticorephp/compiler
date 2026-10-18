<?php
$cases = [
    "<?php\r// a\r\$x = 1;\r",
    "<?php\r\n// a\r\n\$x = 1;\r\n",
    "<?php\r# a\r\$x = 1;",
    "<?php // a\r?> html",
    "<?php // a\n?> html",
    "<?php // a\r",
    "<?php // a",
    "<?php # a\r\r\n\$y;",
    "<?php \$s = <<<X\n// not a comment\r# nor this\nX;\n// c\r\$z;",
];
foreach ($cases as $src) {
    foreach (token_get_all($src) as $t) {
        echo is_array($t) ? token_name($t[0]) . json_encode($t[1]) : json_encode($t), ' ';
    }
    echo "\n";
}
