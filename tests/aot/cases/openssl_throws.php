<?php

// Superset: where php WARNS and carries on, this build throws (no oracle).

function t(callable $f): void {
    try {
        var_dump($f());
    } catch (\ValueError $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

t(fn() => openssl_encrypt('a', 'nosuch', 'k'));
t(fn() => openssl_cipher_iv_length('nosuch'));
t(fn() => openssl_cipher_key_length('nosuch'));
t(fn() => openssl_encrypt('a', 'aes-128-cbc', 'k', 0, 'short'));
t(fn() => openssl_encrypt('a', 'aes-128-cbc', 'k'));
t(fn() => openssl_digest('a', 'nosuch'));
t(fn() => openssl_random_pseudo_bytes(0));
t(fn() => openssl_pbkdf2('a', 'b', 0, 1, 'sha1'));
t(fn() => openssl_pbkdf2('a', 'b', 5, 1, 'nosuch'));
t(fn() => openssl_sign('x', $s, 'not a key'));
t(fn() => openssl_verify('x', 'sig', 'not a key'));
t(fn() => openssl_public_encrypt('x', $e, 'not a key'));
t(fn() => openssl_x509_parse('not a cert'));
