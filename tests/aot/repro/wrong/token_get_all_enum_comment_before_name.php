<?php
// token_get_all() lexes `enum` as T_STRING when a comment (not only whitespace) sits between it and the name
// issue: #361
foreach (['enum /* c */ Foo {}', "enum/* c */Foo {}", "enum // c\nFoo {}", 'enum Foo {}'] as $src) {
    foreach (token_get_all('<?php ' . $src) as $t) {
        if (is_array($t) && $t[0] !== T_OPEN_TAG && $t[0] !== T_WHITESPACE) {
            echo token_name($t[0]), ' ';
            break;
        }
    }
}
echo "\n";
