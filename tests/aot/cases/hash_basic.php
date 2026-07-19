<?php

// Hashing on libcrypto EVP + pure-PHP crc32. Known vectors are byte-stable, so
// this difftests against php exactly.

var_dump(md5(''));
var_dump(md5('abc'));
var_dump(md5('The quick brown fox jumps over the lazy dog'));
var_dump(sha1(''));
var_dump(sha1('abc'));
var_dump(hash('sha256', 'abc'));
var_dump(hash('sha256', ''));
var_dump(hash('sha384', 'abc'));
var_dump(hash('sha512', 'abc'));
var_dump(hash('sha224', 'abc'));

// raw output → hex via bin2hex, must round-trip to the hex form.
var_dump(bin2hex(md5('abc', true)) === md5('abc'));
var_dump(strlen(sha1('x', true)));

// HMAC known vector (RFC 4231-ish): key "key", data "The quick...".
var_dump(hash_hmac('sha256', 'The quick brown fox jumps over the lazy dog', 'key'));
var_dump(hash_hmac('md5', 'data', 'secret'));

// crc32 family
var_dump(crc32('The quick brown fox jumps over the lazy dog'));
var_dump(crc32(''));
var_dump(hash('crc32b', 'abc'));
var_dump(hash('crc32', 'abc'));

// unknown algo → ValueError (php 8)
try {
    hash('nope', 'x');
    var_dump('no throw');
} catch (\ValueError $e) {
    var_dump('ValueError');
}
