<?php
// token_get_all() splits a reserved keyword followed by a backslash name (use\C, new\X) instead of one T_NAME_QUALIFIED
// issue: #363
foreach (['<?php use\C;', '<?php $a = new\X;', '<?php function\f();', '<?php echo\A\b();'] as $src) {
    $out = [];
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && $t[0] !== T_OPEN_TAG && $t[0] !== T_WHITESPACE) {
            $out[] = token_name($t[0]) . ' ' . $t[1];
        }
    }
    echo implode(' | ', $out), "\n";
}
