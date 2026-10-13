<?php

// Superset rule: where php 8.5 emits a warning and carries on (returning false / -1), this build
// throws a ValueError. No oracle: the messages are the warnings' texts.

$rsa = <<<'PEM'
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
$cert = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIDzzCCAregAwIBAgIJEjRWeJCrze8SMA0GCSqGSIb3DQEBCwUAMHAxCzAJBgNV
BAYTAlVBMQ0wCwYDVQQIDARLeWl2MRIwEAYDVQQKDAlNYW50aWNvcmUxETAPBgNV
BAsMCENvbXBpbGVyMRUwEwYDVQQDDAxleGFtcGxlLnRlc3QxFDASBgkqhkiG9w0B
CQEWBWFAYi5jMCAXDTI2MTAxMzEwMTcwMloYDzIxMjYwOTE5MTAxNzAyWjBwMQsw
CQYDVQQGEwJVQTENMAsGA1UECAwES3lpdjESMBAGA1UECgwJTWFudGljb3JlMREw
DwYDVQQLDAhDb21waWxlcjEVMBMGA1UEAwwMZXhhbXBsZS50ZXN0MRQwEgYJKoZI
hvcNAQkBFgVhQGIuYzCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAKb3
+aDPMNYA3YqPj8zZguoSVJZ4Rdb7pvjjSvdP/8e+o6FZJUk0b45MCQvsRE87spoW
6CjZST5An/1P6l3bMEinG8qXD6pqKoVv69S1OhJOSTLN1kIcSg1vqA2rexRO9rnX
TvfP73bsDVSboBEo657+BRXW1cuy85Bekzldt66/1FS4XM4ns+WdSSgOHmNa+2Nl
qyQk1jLETj1RqqavrLHxt7QPSpT7fiGW1lyuNm9mneLqPBZaYzMLHuDKK55F5oC1
M1r7CzdILs3/3FNEUOCsf04Ey8+6ZYevgYv4R3dCiuHYwWMQnEySnumxXCFpngTi
Cke/8+NQ/nbD5d/ATfMCAwEAAaNqMGgwHQYDVR0OBBYEFKKnDHUubIwms+RVmvm5
d3nDvkfaMCkGA1UdEQQiMCCCDGV4YW1wbGUudGVzdIIQd3d3LmV4YW1wbGUudGVz
dDAPBgNVHRMBAf8EBTADAQH/MAsGA1UdDwQEAwIChDANBgkqhkiG9w0BAQsFAAOC
AQEAM4trJO6be9bDTaaFynwnjkHrMtJFYcyHXsQG0aI+KW8Q6V3KwUM1ml2m47gx
5ZT/EwcVPm561ysSzMFExQFAAdeTYYCDH2f/44GnSgM4IVSpk9XQEW9FnecrmIZq
i1dT4QyAEDY2o4EYnfu7oynMN9AUTMcUJGKI5kh+ApDwb2C12w3FyJs/sxlNcjX8
b3qr4oBAFKuI0ZGBk5zWWuCp9ktWQMjb7KhTEdvLb415GJLJB7+JT8iVNWDWNni6
Z/l1zI3fSBKtWMOiRM7rAedYUX8QlU5J9CZFwNrGf5tif4gd1IVxxDyyuQg4UH1t
AurdNlRp0qgQ4KnmPCUa0wbhmg==
-----END CERTIFICATE-----
PEM;
$dir = sys_get_temp_dir() . '/mc_thr_' . getmypid();
mkdir($dir);
file_put_contents("$dir/in", 'x');
file_put_contents("$dir/junk", 'junk');
$k = openssl_pkey_get_private($rsa);
$pub = openssl_pkey_get_public(openssl_pkey_get_details($k)['key']);
$other = openssl_pkey_new(['private_key_bits' => 1024]);
$csr = openssl_csr_new(['CN' => 'a'], $k);

function t(string $name, callable $f): void {
    try {
        $f();
        echo "$name: no exception\n";
    } catch (\ValueError $e) {
        echo str_replace($GLOBALS['dir'], 'DIR', $e->getMessage()), "\n";
    }
}

t('x509_read', fn() => openssl_x509_read('junk'));
t('x509_export', fn() => openssl_x509_export('junk', $o));
t('x509_export_to_file', fn() => openssl_x509_export_to_file('junk', "$dir/o"));
t('x509_fingerprint', fn() => openssl_x509_fingerprint('junk'));
t('x509_verify', fn() => openssl_x509_verify($cert, $k));
t('x509_check_private_key', fn() => openssl_x509_check_private_key($cert, $pub));
t('x509_checkpurpose', fn() => openssl_x509_checkpurpose($cert, X509_PURPOSE_ANY, ["$dir/none"]));
t('pkey_get_public', fn() => openssl_pkey_get_public($k));
t('pkey_derive', fn() => openssl_pkey_derive($k, $k));
t('pkey_export', fn() => openssl_pkey_export('junk', $o));
t('pkey_export_to_file', fn() => openssl_pkey_export_to_file('junk', "$dir/o"));
t('pkey_new ec', fn() => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC]));
t('pkey_new ec curve', fn() => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'nosuch']));
t('csr_export', fn() => openssl_csr_export('junk', $o));
t('csr_export_to_file', fn() => openssl_csr_export_to_file('junk', "$dir/o"));
t('csr_sign csr', fn() => openssl_csr_sign('junk', null, $k, 1));
t('csr_sign ca', fn() => openssl_csr_sign($csr, 'junk', $k, 1));
t('csr_sign key', fn() => openssl_csr_sign($csr, $cert, $other, 1));
t('csr_new config', fn() => openssl_csr_new(['CN' => 'a'], $k, ['config' => '/nonexistent']));
t('spki_verify', fn() => openssl_spki_verify('junk'));
t('spki_export', fn() => openssl_spki_export('junk'));
t('spki_export_challenge', fn() => openssl_spki_export_challenge('junk'));
t('seal keys', fn() => openssl_seal('x', $s, $ek, [], 'aes-128-cbc', $iv));
t('seal key', fn() => openssl_seal('x', $s, $ek, ['junk'], 'aes-128-cbc', $iv));
t('encrypt dont_zero_pad', fn() => openssl_encrypt('x', 'aes-128-cbc', 'k', OPENSSL_DONT_ZERO_PAD_KEY, str_repeat('i', 16)));
t('cms_sign input', fn() => openssl_cms_sign("$dir/none", "$dir/o", $cert, $rsa, null));
t('cms_sign cert', fn() => openssl_cms_sign("$dir/in", "$dir/o", 'junk', $rsa, null));
t('cms_sign detached', fn() => openssl_cms_sign("$dir/in", "$dir/o", $cert, $rsa, null, OPENSSL_CMS_DETACHED));
t('cms_sign encoding', fn() => openssl_cms_sign("$dir/in", "$dir/o", $cert, $rsa, null, 0, 9));
t('cms_verify encoding', fn() => openssl_cms_verify("$dir/junk", 0, null, [], null, null, null, null, 9));
t('cms_decrypt cert', fn() => openssl_cms_decrypt("$dir/junk", "$dir/o", 'junk', $rsa));
t('cms_decrypt key', fn() => openssl_cms_decrypt("$dir/junk", "$dir/o", $cert));
t('cms_encrypt cipher', fn() => openssl_cms_encrypt("$dir/in", "$dir/o", $cert, null, 0, 1, 99));
t('pkcs7_sign input', fn() => openssl_pkcs7_sign("$dir/none", "$dir/o", $cert, $rsa, null));
t('pkcs7_sign cert', fn() => openssl_pkcs7_sign("$dir/in", "$dir/o", 'junk', $rsa, null));
t('pkcs7_decrypt cert', fn() => openssl_pkcs7_decrypt("$dir/junk", "$dir/o", 'junk', $rsa));
t('pkcs7_decrypt key', fn() => openssl_pkcs7_decrypt("$dir/junk", "$dir/o", $cert));
t('pkcs7_encrypt cipher', fn() => openssl_pkcs7_encrypt("$dir/in", "$dir/o", $cert, null, 0, 99));
array_map('unlink', glob("$dir/*"));
rmdir($dir);
