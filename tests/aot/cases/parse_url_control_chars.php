<?php
// parse_url() replaces every control byte of a component with `_`, as php's
// php_replace_controlchars does: a CR/LF in a URL must never come back out of
// the parse to be put into a request line or a header. Zend oracle.

$urls = [
    "ws://ex.com/a\r\nX-Evil: 1",
    "ws://ex\r\n.com/a",
    "http://ex\x01.com:8080/p?q\x7f=1\x00#f\tg",
    "ws://us\x02er:p\x03@h/",
    "s\x01c://h/",
    "/p\x1fa",
    "ws://ex.com\n:80/",
    "http://h/clean?x=1#y",
];
foreach ($urls as $u) {
    echo json_encode(parse_url($u)), "\n";
    var_dump(parse_url($u, PHP_URL_PATH), parse_url($u, PHP_URL_HOST));
}
