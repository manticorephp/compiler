<?php

// PKCS#12 and PKCS#7 (S-MIME) over libcrypto. Salts and boundaries are random, so the
// writers are checked by roundtrip; the readers by a fixed php-made blob and a fixed PEM.

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
$p7pem = <<<'PEM'
-----BEGIN PKCS7-----
MIID/gYJKoZIhvcNAQcCoIID7zCCA+sCAQExADALBgkqhkiG9w0BBwGgggPTMIID
zzCCAregAwIBAgIJEjRWeJCrze8SMA0GCSqGSIb3DQEBCwUAMHAxCzAJBgNVBAYT
AlVBMQ0wCwYDVQQIDARLeWl2MRIwEAYDVQQKDAlNYW50aWNvcmUxETAPBgNVBAsM
CENvbXBpbGVyMRUwEwYDVQQDDAxleGFtcGxlLnRlc3QxFDASBgkqhkiG9w0BCQEW
BWFAYi5jMCAXDTI2MTAxMzEwMTcwMloYDzIxMjYwOTE5MTAxNzAyWjBwMQswCQYD
VQQGEwJVQTENMAsGA1UECAwES3lpdjESMBAGA1UECgwJTWFudGljb3JlMREwDwYD
VQQLDAhDb21waWxlcjEVMBMGA1UEAwwMZXhhbXBsZS50ZXN0MRQwEgYJKoZIhvcN
AQkBFgVhQGIuYzCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAKb3+aDP
MNYA3YqPj8zZguoSVJZ4Rdb7pvjjSvdP/8e+o6FZJUk0b45MCQvsRE87spoW6CjZ
ST5An/1P6l3bMEinG8qXD6pqKoVv69S1OhJOSTLN1kIcSg1vqA2rexRO9rnXTvfP
73bsDVSboBEo657+BRXW1cuy85Bekzldt66/1FS4XM4ns+WdSSgOHmNa+2NlqyQk
1jLETj1RqqavrLHxt7QPSpT7fiGW1lyuNm9mneLqPBZaYzMLHuDKK55F5oC1M1r7
CzdILs3/3FNEUOCsf04Ey8+6ZYevgYv4R3dCiuHYwWMQnEySnumxXCFpngTiCke/
8+NQ/nbD5d/ATfMCAwEAAaNqMGgwHQYDVR0OBBYEFKKnDHUubIwms+RVmvm5d3nD
vkfaMCkGA1UdEQQiMCCCDGV4YW1wbGUudGVzdIIQd3d3LmV4YW1wbGUudGVzdDAP
BgNVHRMBAf8EBTADAQH/MAsGA1UdDwQEAwIChDANBgkqhkiG9w0BAQsFAAOCAQEA
M4trJO6be9bDTaaFynwnjkHrMtJFYcyHXsQG0aI+KW8Q6V3KwUM1ml2m47gx5ZT/
EwcVPm561ysSzMFExQFAAdeTYYCDH2f/44GnSgM4IVSpk9XQEW9FnecrmIZqi1dT
4QyAEDY2o4EYnfu7oynMN9AUTMcUJGKI5kh+ApDwb2C12w3FyJs/sxlNcjX8b3qr
4oBAFKuI0ZGBk5zWWuCp9ktWQMjb7KhTEdvLb415GJLJB7+JT8iVNWDWNni6Z/l1
zI3fSBKtWMOiRM7rAedYUX8QlU5J9CZFwNrGf5tif4gd1IVxxDyyuQg4UH1tAurd
NlRp0qgQ4KnmPCUa0wbhmjEA
-----END PKCS7-----
PEM;
$p12 = base64_decode(<<<'B64'
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
HXVnlRRd41duH3eJbrRP4wICCAA=
B64);

// PKCS#12: fixed blob made by php, then our own export read back
var_dump(openssl_pkcs12_read($p12, $out, 'secret'));
echo implode(',', array_keys($out)), "\n";
var_dump(trim($out['cert']) === trim($cert));
var_dump(openssl_pkey_get_details(openssl_pkey_get_private($out['pkey']))['rsa']['d'] === openssl_pkey_get_details(openssl_pkey_get_private($rsa))['rsa']['d']);
var_dump(openssl_pkcs12_read($p12, $bad, 'wrong'));
var_dump(openssl_pkcs12_export($cert, $mine, $rsa, 'pw', ['friendly_name' => 'x', 'extracerts' => [$cert]]));
var_dump(openssl_pkcs12_read($mine, $back, 'pw'));
echo implode(',', array_keys($back)), ' ', count($back['extracerts']), "\n";
var_dump(trim($back['cert']) === trim($cert), trim($back['extracerts'][0]) === trim($cert));
$tmp = tempnam(sys_get_temp_dir(), 'mcp');
var_dump(openssl_pkcs12_export_to_file($cert, $tmp, $rsa, 'pw'), openssl_pkcs12_read(file_get_contents($tmp), $b2, 'pw'));

// PKCS#7 read from a PEM
var_dump(openssl_pkcs7_read($p7pem, $certs), count($certs), trim($certs[0]) === trim($cert));
var_dump(openssl_pkcs7_read('garbage', $none));

// sign / verify / encrypt / decrypt through files
$in = tempnam(sys_get_temp_dir(), 'mci');
$sig = tempnam(sys_get_temp_dir(), 'mcs');
$ca = tempnam(sys_get_temp_dir(), 'mcc');
$content = tempnam(sys_get_temp_dir(), 'mco');
file_put_contents($in, "signed message\nline two\n");
file_put_contents($ca, $cert);
var_dump(openssl_pkcs7_sign($in, $sig, $cert, $rsa, ['To' => 'a@b.c', 'Subject' => 'hi'], PKCS7_DETACHED));
$s = file_get_contents($sig);
var_dump(str_starts_with($s, "To: a@b.c\nSubject: hi\nMIME-Version: 1.0\n"), str_contains($s, 'multipart/signed'));
var_dump(openssl_pkcs7_verify($sig, PKCS7_DETACHED, null, [$ca]));
var_dump(openssl_pkcs7_verify($sig, PKCS7_NOVERIFY));
$signers = tempnam(sys_get_temp_dir(), 'mcg');
var_dump(openssl_pkcs7_verify($sig, 0, $signers, [$ca]), trim(file_get_contents($signers)) === trim($cert));
file_put_contents($sig, str_replace('line two', 'line TWO', $s));
var_dump(openssl_pkcs7_verify($sig, 0, null, [$ca]));
var_dump(openssl_pkcs7_sign($in, $sig, $cert, $rsa, null, PKCS7_BINARY));
var_dump(openssl_pkcs7_verify($sig, 0, null, [$ca], null, $content), file_get_contents($content));

$enc = tempnam(sys_get_temp_dir(), 'mce');
$dec = tempnam(sys_get_temp_dir(), 'mcd');
var_dump(openssl_pkcs7_encrypt($in, $enc, $cert, ['To' => 'x@y.z'], 0, OPENSSL_CIPHER_AES_256_CBC));
var_dump(str_starts_with(file_get_contents($enc), "To: x@y.z\nMIME-Version: 1.0\n"), str_contains(file_get_contents($enc), 'application/x-pkcs7-mime'));
var_dump(openssl_pkcs7_decrypt($enc, $dec, $cert, $rsa), file_get_contents($dec));
foreach ([$tmp, $in, $sig, $ca, $content, $signers, $enc, $dec] as $f) { unlink($f); }
