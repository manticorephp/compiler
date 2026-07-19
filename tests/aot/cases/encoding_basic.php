<?php

// base64 / URL encoding / URL & query parsing — all pure PHP, byte-exact vs php.

var_dump(base64_encode(''));
var_dump(base64_encode('f'));
var_dump(base64_encode('fo'));
var_dump(base64_encode('foo'));
var_dump(base64_encode('foobar'));
var_dump(base64_encode('The quick brown fox'));
var_dump(base64_decode('Zm9vYmFy'));
var_dump(base64_decode('VGhlIHF1aWNrIGJyb3duIGZveA=='));
// binary round-trip verified via bin2hex (echo/var_dump of a raw string with an
// embedded NUL truncates — a separate pre-existing output bug, tracked apart).
var_dump(bin2hex(base64_decode(base64_encode("\x00\x01\xff\xfe binary"))));

var_dump(urlencode('a b+c/d?e=f&g'));
var_dump(rawurlencode('a b+c/d?e=f&g~h'));
var_dump(urldecode('a+b%2Bc%2Fd'));
var_dump(rawurldecode('a%20b%7Ec'));
var_dump(urlencode('hello world!'));
var_dump(rawurlencode('hello world!'));

// parse_url — full array + single components
$u = parse_url('https://user:pass@example.com:8080/path/to?x=1&y=2#frag');
var_dump($u['scheme']);
var_dump($u['host']);
var_dump($u['port']);
var_dump($u['user']);
var_dump($u['pass']);
var_dump($u['path']);
var_dump($u['query']);
var_dump($u['fragment']);
var_dump(parse_url('http://host/p', PHP_URL_SCHEME));
var_dump(parse_url('http://host:90/p', PHP_URL_PORT));
var_dump(parse_url('/just/a/path?q=1', PHP_URL_PATH));
var_dump(parse_url('mailto:a@b.com', PHP_URL_SCHEME));

// NOTE: parse_str() and http_build_query() are implemented but NOT asserted
// here. Both read array element VALUES across the stdlib boundary
// (parse_str's `&$result`, http_build_query's `$data`) — those come back UNBOXED
// (garbage) until the separate repr-consistency epic (by-ref / cell-element
// boxing) merges. They start passing once it does.
