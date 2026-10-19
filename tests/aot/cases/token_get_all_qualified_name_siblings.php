<?php
// Any label followed by `\Seg` is one name token (a reserved word, a magic
// constant, a cast word included); `namespace\X` is relative; after `->` the
// property name stays a bare label.
foreach (['<?php Use\C;', '<?php yield\x;', '<?php yield\from;', '<?php __CLASS__\x;', '<?php fn\x;',
    '<?php enum\x;', '<?php static\x;', '<?php isset\x;', '<?php array\x;', '<?php $o->use\C;',
    '<?php $o?->foo\bar;', '<?php A::class\x;', '<?php namespace\A\b;', '<?php Namespace\A;',
    '<?php function use\x(){}', '<?php \use\x;'] as $src) {
    $out = [];
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && $t[0] !== T_OPEN_TAG && $t[0] !== T_WHITESPACE) {
            $out[] = token_name($t[0]) . ' ' . $t[1];
        } elseif (!is_array($t)) {
            $out[] = $t;
        }
    }
    echo implode(' | ', $out), "\n";
}
