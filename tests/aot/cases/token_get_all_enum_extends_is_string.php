<?php
// token_get_all() lexes `enum` as T_ENUM in `class enum extends B` / `class enum implements C`; Zend gives T_STRING
foreach (['class enum extends B {}', 'class enum implements C {}', 'class enum {}'] as $src) {
    $names = [];
    foreach (token_get_all('<?php ' . $src) as $t) {
        if (is_array($t) && $t[0] !== T_OPEN_TAG && $t[0] !== T_WHITESPACE) {
            $names[] = token_name($t[0]);
        }
    }
    echo implode(' ', $names), "\n";
}
