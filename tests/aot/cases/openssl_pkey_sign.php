<?php

// ext/openssl asymmetric half: sign/verify, pkey generation + export, raw RSA.
// RSA PKCS#1 v1.5 signatures and private_encrypt are deterministic, so they are
// compared against php's bytes through a digest; randomised paths roundtrip.

$priv = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCm9/mgzzDWAN2K
j4/M2YLqElSWeEXW+6b440r3T//HvqOhWSVJNG+OTAkL7ERPO7KaFugo2Uk+QJ/9
T+pd2zBIpxvKlw+qaiqFb+vUtToSTkkyzdZCHEoNb6gNq3sUTva51073z+927A1U
m6ARKOue/gUV1tXLsvOQXpM5Xbeuv9RUuFzOJ7PlnUkoDh5jWvtjZaskJNYyxE49
Uaqmr6yx8be0D0qU+34hltZcrjZvZp3i6jwWWmMzCx7gyiueReaAtTNa+ws3SC7N
/9xTRFDgrH9OBMvPumWHr4GL+Ed3Qorh2MFjEJxMkp7psVwhaZ4E4gpHv/PjUP52
w+XfwE3zAgMBAAECggEAErTcoGImMAvHxmLgMwy5iJ0GIheWh4gJRIA+oWeymf/n
WEfjEAkSaIhnSREu9AuVroLbftwdQx69bgV6lgpdJf6m8/Mpu6eKXQnVi67EM1HX
DJyYIS8HMussEmAAeZ2BMgAH5mbDBvlaYvzOma2SfG0sR66apGhHtoiKCRbRYgAI
gG2NZbm1dbQHJF4P5ytqXtKo/2nndeiASfrX5CjaVCAU2p4yckrnsvnaKJjAwuWl
cpQu37MGH4OQmovXnH+1a7Rq6sJLMMgGlW5HbXRB2u6lOebsfCs2ByRp63VZCX4y
GE575Cy4YOEHa4c7Y921pSdBn+9JBjMG3MImsvGptQKBgQDgTLJ+wEYXHewgi3WW
ADOR2SppmXbJYWIq9OkPGYhs20yCZSfcdx/7R0/zdWRJC+u8c739akIhl967voVX
68Myr2Bj+TaRDfe8922eqzaxrvJm7m7gRcxUq6eycaY8q/DlWD5qdxmfLl3Q/cis
bvBbxRJeLZ5O3hVDRJcEe53ATwKBgQC+kQHzfKC9GnsJC20ZLPqyI5qRMHx5x3cn
pEZN8M7QPBb1Koma1P5k9al2wKBCXjfxud4c5ERDQszaBXjJ2nSMwQCfgWIWpxEL
5JeLm4wnymuf9rR3WKQFp8TmYUenA8IRQSHWYm8UxVHjpAdEJq1LkCmHRwYepjkY
w9xBY9frHQKBgDBaWGBassFpAe5CIickA/n9Btec7a6b/72i7lsC9hYBcIzroZw1
Uf+pb+Lb+7KDj14g1WQ78+WqB6oReE1r1bindfAXWVwL1sw1yrvZzQCg5kCqRdyz
L0lRd+VP+rY5msTWMR5ibnOSW0cibpA9mjMuWLdUvjviRFMsBiJBQ9ChAoGBAIVa
afgeXUkXj8OLiGPWsvQXsCErwd9iMsK91SjTe5YChro3cyWzKPYzZswgDcWDszhn
rcOgBRjgVQO4UodGZMregHYeDyr07BvbciUSy2hI4TmGZVEi6Vy/wyzIJGD+Yz65
ylkAduUdE6nt7dvGCPzYvAbbL7Ag99GZtbbYjI8NAoGBAIJjayWdH0bfPVZpaKQj
9Ewi1gURfH51za0gws1tr5s/k/P30J1t+UFp7HHd7/De4hcAvRzdlMX01CTFLbJ+
cXcIao7P1qc4giSeJOciaWtFWTg/zqd1+wmv0h4E+jTcMX6vSU/7BAiL3wjFWS/q
LJFytnlLKU+0Atz7mmxq7qGy
-----END PRIVATE KEY-----
PEM;

$k = openssl_pkey_get_private($priv);
var_dump(get_class($k));
var_dump(openssl_pkey_get_private('garbage'));
$d = openssl_pkey_get_details($k);
var_dump($d['bits'], $d['type']);
$pub = openssl_pkey_get_public($d['key']);

$data = 'message to sign';
foreach ([OPENSSL_ALGO_SHA1, OPENSSL_ALGO_SHA256, OPENSSL_ALGO_SHA512, 'sha384', 'RSA-SHA256'] as $alg) {
    var_dump(openssl_sign($data, $sig, $k, $alg));
    echo sha1($sig), ' ', strlen($sig), "\n";
    var_dump(openssl_verify($data, $sig, $pub, $alg));
    var_dump(openssl_verify($data . 'x', $sig, $pub, $alg));
    var_dump(openssl_verify($data, $sig, $d['key'], $alg));
}
openssl_sign($data, $sig2, $priv);
var_dump(sha1($sig2));

// private_encrypt / public_decrypt are deterministic with PKCS1 padding
var_dump(openssl_private_encrypt('short secret', $pe, $k));
echo sha1($pe), "\n";
var_dump(openssl_public_decrypt($pe, $pd, $pub));
var_dump($pd);
var_dump(openssl_public_decrypt($pe, $bad, openssl_pkey_get_public(openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 1024]))['key'])));

// randomised padding: roundtrip
foreach ([OPENSSL_PKCS1_PADDING, OPENSSL_PKCS1_OAEP_PADDING] as $pad) {
    var_dump(openssl_public_encrypt('top secret', $enc, $pub, $pad));
    var_dump(strlen($enc));
    var_dump(openssl_private_decrypt($enc, $dec, $k, $pad));
    var_dump($dec);
}
var_dump(openssl_public_encrypt(str_repeat('x', 300), $big, $pub));
var_dump(openssl_private_decrypt(str_repeat("\0", 256), $junk, $k, OPENSSL_PKCS1_OAEP_PADDING));

// generation, export, passphrase
$n = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
var_dump(openssl_pkey_get_details($n)['bits']);
var_dump(openssl_pkey_export($n, $out));
var_dump(str_starts_with($out, "-----BEGIN PRIVATE KEY-----\n"));
var_dump(openssl_pkey_export($n, $enc2, 'pw'));
var_dump(str_starts_with($enc2, "-----BEGIN ENCRYPTED PRIVATE KEY-----\n"));
var_dump(openssl_pkey_get_private($enc2, 'wrong'));
$again = openssl_pkey_get_private($enc2, 'pw');
var_dump(get_class($again));
var_dump(openssl_pkey_get_details($again)['rsa']['n'] === openssl_pkey_get_details($n)['rsa']['n']);
var_dump(openssl_pkey_get_private([$enc2, 'pw']) instanceof OpenSSLAsymmetricKey);
openssl_sign('x', $s3, $n, 'sha256');
var_dump(openssl_verify('x', $s3, openssl_pkey_get_public(openssl_pkey_get_details($again)['key']), 'sha256'));

// EC
$ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
var_dump(get_class($ec));
var_dump(openssl_sign('ec data', $es, $ec, OPENSSL_ALGO_SHA256));
var_dump(strlen($es) > 8);
