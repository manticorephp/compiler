<?php

// CCM and OCB AEAD modes, and the real OBJ_NAME registry behind the method lists.

foreach (['aes-128-ccm', 'aes-256-ocb', 'aes-128-gcm'] as $m) {
    $tag = null;
    $c = openssl_encrypt('ccm payload', $m, '0123456789abcdef0123456789abcdef', OPENSSL_RAW_DATA, '1234567890ab', $tag, 'aad', 16);
    echo $m, ' ', bin2hex($c), ' ', bin2hex($tag), "\n";
    var_dump(openssl_decrypt($c, $m, '0123456789abcdef0123456789abcdef', OPENSSL_RAW_DATA, '1234567890ab', $tag, 'aad'));
    var_dump(openssl_decrypt($c, $m, '0123456789abcdef0123456789abcdef', OPENSSL_RAW_DATA, '1234567890ab', $tag, 'bad'));
}
$tag = null;
var_dump(openssl_encrypt('x', 'aes-128-ccm', 'k', 0, '1234567', $tag, '', 8), strlen($tag));

$c = openssl_get_cipher_methods();
$a = openssl_get_cipher_methods(true);
var_dump(in_array('aes-256-cbc', $c), in_array('aes-256-cbc', $a), count($a) >= count($c));
$m = openssl_get_md_methods();
var_dump(in_array('sha256', $m), in_array('sha256', openssl_get_md_methods(true)));
