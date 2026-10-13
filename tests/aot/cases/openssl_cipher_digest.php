<?php

// ext/openssl symmetric half over libcrypto: fixed key/iv ciphertexts, AEAD tag + aad,
// flags, digests, pbkdf2. Deterministic outputs only.

$key = 'abcdefghijklmnopqrstuvwxyz012345';
$iv16 = '0123456789abcdef';

foreach (['aes-256-cbc', 'aes-128-cbc', 'aes-256-ctr', 'aes-192-cfb', 'aes-256-ofb', 'des-ede3-cbc'] as $m) {
    $ivl = openssl_cipher_iv_length($m);
    $iv = substr($iv16 . $iv16, 0, $ivl);
    $c = openssl_encrypt('The quick brown fox jumps', $m, $key, 0, $iv);
    echo $m, ' ', openssl_cipher_key_length($m), ' ', $ivl, ' ', $c, "\n";
    var_dump(openssl_decrypt($c, $m, $key, 0, $iv));
}

// raw data, ecb (no iv), zero padding
$raw = openssl_encrypt('hello world', 'aes-128-ecb', 'k', OPENSSL_RAW_DATA);
echo bin2hex($raw), "\n";
var_dump(openssl_decrypt($raw, 'aes-128-ecb', 'k', OPENSSL_RAW_DATA));
$z = openssl_encrypt(str_repeat('x', 16), 'aes-128-ecb', 'k', OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
echo bin2hex($z), "\n";
var_dump(openssl_decrypt($z, 'aes-128-ecb', 'k', OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING));
var_dump(openssl_decrypt('not base64 !!', 'aes-128-ecb', 'k'));
var_dump(openssl_decrypt(str_repeat("\1", 16), 'aes-128-ecb', 'k', OPENSSL_RAW_DATA));

// AEAD
$tag = null;
$c = openssl_encrypt('secret payload', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, '123456789012', $tag, 'header');
echo bin2hex($c), ' ', bin2hex($tag), "\n";
var_dump(openssl_decrypt($c, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, '123456789012', $tag, 'header'));
var_dump(openssl_decrypt($c, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, '123456789012', $tag, 'other'));
$tag2 = null;
$c = openssl_encrypt('x', 'aes-128-gcm', 'k', 0, 'iv', $tag2, '', 12);
var_dump(strlen($tag2), $c);

$cc = openssl_encrypt('chacha payload', 'chacha20-poly1305', $key, OPENSSL_RAW_DATA, '123456789012', $t3, 'aad');
echo bin2hex($cc), ' ', bin2hex($t3), "\n";
var_dump(openssl_decrypt($cc, 'chacha20-poly1305', $key, OPENSSL_RAW_DATA, '123456789012', $t3, 'aad'));

// method lists
$ciphers = openssl_get_cipher_methods();
var_dump(in_array('aes-256-cbc', $ciphers), in_array('aes-256-gcm', $ciphers), $ciphers === array_values(array_unique($ciphers)));
$mds = openssl_get_md_methods();
var_dump(in_array('sha256', $mds), in_array('md5', $mds));

// digests and kdf
var_dump(openssl_digest('abc', 'sha256'));
var_dump(bin2hex(openssl_digest('abc', 'md5', true)));
var_dump(openssl_digest('', 'SHA1'));
var_dump(bin2hex(openssl_pbkdf2('password', 'salt', 20, 4096, 'sha1')));
var_dump(bin2hex(openssl_pbkdf2('password', 'salt', 32, 1000, 'sha256')));

// random
$r = openssl_random_pseudo_bytes(24, $strong);
var_dump(strlen($r), $strong);
var_dump(openssl_random_pseudo_bytes(8) !== openssl_random_pseudo_bytes(8));

// errors
var_dump(openssl_error_string() === false || is_string(openssl_error_string()));
