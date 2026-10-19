<?php
// TOKEN_PARSE: `a as protected;` keeps the member modifier; `a as echo;` and
// `a as final list;` make the reserved word the alias name.
foreach (['public', 'protected', 'private', 'static', 'abstract', 'final', 'echo', 'protected echo',
    'final list', 'public function', 'var', 'private c'] as $w) {
    $src = "<?php class A { use T { a as $w; b as private c; } }";
    $out = [];
    foreach (token_get_all($src, TOKEN_PARSE) as $t) {
        if (is_array($t) && $t[0] !== T_WHITESPACE && $t[0] !== T_OPEN_TAG) {
            $out[] = token_name($t[0]) . ' ' . $t[1];
        }
    }
    echo $w, ': ', implode(' | ', array_slice($out, 5)), "\n";
}
