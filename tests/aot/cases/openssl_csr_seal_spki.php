<?php

// CSR, certificate issue, seal/open, SPKAC and EC curve / location introspection.
// RSA PKCS#1 v1.5 requests and SPKACs are deterministic, so they match php byte for byte.

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

$dn = ['countryName' => 'UA', 'stateOrProvinceName' => 'Kyiv', 'localityName' => 'Kyiv', 'organizationName' => 'Manticore',
       'organizationalUnitName' => 'Compiler', 'commonName' => 'leaf.example.test', 'emailAddress' => 'leaf@example.test'];
$key = openssl_pkey_get_private($rsa);
$csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
var_dump(get_class($csr));
var_dump(openssl_csr_export($csr, $pem));
echo $pem;
var_dump(openssl_csr_export($csr, $txt, false));
var_dump(str_starts_with($txt, "Certificate Request:\n"), str_contains($txt, 'Subject: C=UA, ST=Kyiv'));
print_r(openssl_csr_get_subject($csr));
print_r(openssl_csr_get_subject($pem));
var_dump(openssl_pkey_get_details(openssl_csr_get_public_key($csr))['key'] === openssl_pkey_get_details($key)['key']);
$tmp = tempnam(sys_get_temp_dir(), 'mcc');
var_dump(openssl_csr_export_to_file($csr, $tmp), file_get_contents($tmp) === $pem);
unlink($tmp);

// a fresh key is generated into the by-ref slot when none is given
$none = null;
$csr2 = openssl_csr_new(['commonName' => 'fresh.test'], $none, ['private_key_bits' => 1024]);
var_dump(get_class($csr2), get_class($none), openssl_pkey_get_details($none)['bits']);

// issue: self-signed, then signed by the fixture CA
$self = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256'], 7);
var_dump(get_class($self));
$p = openssl_x509_parse($self);
var_dump($p['version'], $p['serialNumber'], $p['subject'] === $p['issuer'], $p['subject']['CN'], $p['signatureTypeSN']);
var_dump($p['validTo_time_t'] - $p['validFrom_time_t']);
var_dump(openssl_x509_verify($self, openssl_pkey_get_details($key)['key']), openssl_x509_check_private_key($self, $key));
$issued = openssl_csr_sign($csr, $cert, $key, 365, null, 99);
$pi = openssl_x509_parse($issued);
var_dump($pi['issuer'] === openssl_x509_parse($cert)['subject'], $pi['subject']['CN'], $pi['serialNumber'], $pi['validTo_time_t'] - $pi['validFrom_time_t']);
var_dump(openssl_x509_verify($issued, openssl_pkey_get_details($key)['key']));

// seal / open
$pub = openssl_pkey_get_public(openssl_pkey_get_details($key)['key']);
$k2 = openssl_pkey_new(['private_key_bits' => 1024]);
$pub2 = openssl_pkey_get_public(openssl_pkey_get_details($k2)['key']);
foreach (['aes-128-cbc', 'aes-256-ecb', 'aes-128-ctr'] as $c) {
    $iv = null;
    $n = openssl_seal('sealed message ' . $c, $sealed, $ek, [$pub, $pub2], $c, $iv);
    var_dump($n === strlen($sealed), count($ek), strlen($ek[0]), strlen($ek[1]), $iv === null ? null : strlen($iv));
    var_dump(openssl_open($sealed, $out, $ek[0], $key, $c, $iv), $out);
    var_dump(openssl_open($sealed, $out2, $ek[1], $k2, $c, $iv), $out2);
}

// SPKAC
foreach ([[], [OPENSSL_ALGO_SHA256]] as $extra) {
    $s = openssl_spki_new($key, 'challenge', ...$extra);
    echo $s, "\n";
    $bare = substr($s, strlen('SPKAC='));
    var_dump(openssl_spki_verify($bare), openssl_spki_export($bare) === openssl_pkey_get_details($key)['key'], openssl_spki_export_challenge($bare));
}

// curves, default locations
$curves = openssl_get_curve_names();
var_dump(in_array('prime256v1', $curves), in_array('secp384r1', $curves), count($curves) > 10);
$loc = openssl_get_cert_locations();
echo implode(',', array_keys($loc)), "\n";
var_dump($loc['default_cert_file_env'], $loc['default_cert_dir_env'], is_string($loc['default_cert_file']));
