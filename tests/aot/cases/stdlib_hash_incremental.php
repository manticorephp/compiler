<?php
// hash_init/update/final over the one-shot digest path. The context BUFFERS —
// see the HashContext docblock for why that is the honest trade — so what this
// asserts is that every incremental answer equals the one-shot one.
$c = hash_init('sha256');
hash_update($c, "hello ");
hash_update($c, "world");
var_dump(hash_final($c) === hash('sha256', 'hello world'));
var_dump(hash_final(hash_init('md5')) === hash('md5', ''));

$m = hash_init('md5');
hash_update($m, "abc");
var_dump(hash_final($m, true) === hash('md5', 'abc', true));

$h = hash_init('sha256', HASH_HMAC, 'secret');
hash_update($h, "pay");
hash_update($h, "load");
var_dump(hash_final($h) === hash_hmac('sha256', 'payload', 'secret'));

// Every algo the one-shot path knows, incrementally.
foreach (['md5', 'sha1', 'sha224', 'sha256', 'sha384', 'sha512'] as $algo) {
    $x = hash_init($algo);
    hash_update($x, "the quick brown fox");
    var_dump($algo, hash_final($x) === hash($algo, 'the quick brown fox'));
}

// A finalised context refuses more data, as php's does.
$done = hash_init('md5');
hash_final($done);
try { hash_update($done, "more"); echo "no throw\n"; }
catch (\Error $e) { echo "throws: ", get_class($e), "\n"; }

// An unknown algo is a ValueError at init.
try { hash_init('nope'); echo "no throw\n"; }
catch (\ValueError $e) { echo "throws: ValueError\n"; }
