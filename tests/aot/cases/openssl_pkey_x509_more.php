<?php

// Key introspection and agreement, certificate export / verify / purpose checks.
// Fixed fixtures are compared with php byte for byte; generated keys roundtrip.

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
$ec1 = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgoZeFO+y/D3/RIh6a
d72Dhl7oF6Ohl4uT63BOZn66pqmhRANCAAQ8jNYpdFjFmfQZyZK1mUIF5Hcn7MBj
QV+3bacy1f/o/KVVNBhWHxwZRhQc11nEMEq/Yt9D/qlkJVy9pZ7JHxAm
-----END PRIVATE KEY-----
PEM;
$ec2 = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgqSyH4ZtFQi7rJKu0
snlvcAwILjFckeOWiZv5d9dtZGuhRANCAATe6DmVenaXDa43Lxil5cr64O3GEeba
DAjxszFouczHhWS8/hQBGeTBWSRjZTgHUgHGuzcDV5JcXZi4vWV1ew/r
-----END PRIVATE KEY-----
PEM;
$ec384 = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIG2AgEAMBAGByqGSM49AgEGBSuBBAAiBIGeMIGbAgEBBDCHRQ9rViT9w6tAQfiP
TzhtKcLy/v1eIxsxlLejiyyO/8EE5bILW2vmhkm+gpH+x0uhZANiAAQOZx8uDG1X
8ZN8ESMrDmJJqqyNio8DcCDPmxWG46HvUdKOZmxLXegHSkTEvfsk0/Pqn4OZ+tDH
g/ZwLC/O5NswMwQ+MYVQeSVNlHDles6bXZsjFDN6bv70EG+NjCr4oos=
-----END PRIVATE KEY-----
PEM;

function show(array $a, string $ind = ''): void {
    foreach ($a as $k => $v) {
        if (is_array($v)) { echo $ind, $k, ":\n"; show($v, $ind . '  '); }
        elseif (is_string($v) && !ctype_print($v)) { echo $ind, $k, '=', bin2hex($v), "\n"; }
        else { echo $ind, $k, '=', $v, "\n"; }
    }
}

// details: RSA private (every CRT field), EC private and public, EC 384
$k = openssl_pkey_get_private($rsa);
$d = openssl_pkey_get_details($k);
echo implode(',', array_keys($d)), ' ', implode(',', array_keys($d['rsa'])), "\n";
foreach ($d['rsa'] as $n => $v) { echo $n, ' ', md5($v), "\n"; }
$e1 = openssl_pkey_get_private($ec1);
show(openssl_pkey_get_details($e1));
$pub1 = openssl_pkey_get_public(openssl_pkey_get_details($e1)['key']);
show(openssl_pkey_get_details($pub1));
$d = openssl_pkey_get_details(openssl_pkey_get_private($ec384));
echo $d['bits'], ' ', $d['ec']['curve_name'], ' ', $d['ec']['curve_oid'], "\n";
var_dump(openssl_pkey_get_public($pub1) === $pub1);
var_dump(openssl_get_publickey($cert) instanceof OpenSSLAsymmetricKey, openssl_get_privatekey($rsa) instanceof OpenSSLAsymmetricKey);

// ECDH: fixed keys are deterministic
$e2 = openssl_pkey_get_private($ec2);
$pub2 = openssl_pkey_get_public(openssl_pkey_get_details($e2)['key']);
echo bin2hex(openssl_pkey_derive($pub2, $e1)), "\n";
var_dump(openssl_pkey_derive($pub2, $e1) === openssl_pkey_derive($pub1, $e2));

// generated: X25519, DH (fixed group), keys round trip through export
$xa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_X25519]);
$xb = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_X25519]);
$pa = openssl_pkey_get_public(openssl_pkey_get_details($xa)['key']);
$pb = openssl_pkey_get_public(openssl_pkey_get_details($xb)['key']);
var_dump(openssl_pkey_derive($pb, $xa) === openssl_pkey_derive($pa, $xb), strlen(openssl_pkey_derive($pb, $xa)));
echo implode(',', array_keys(openssl_pkey_get_details($xa))), "\n";
$ed = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);
echo implode(',', array_keys(openssl_pkey_get_details($ed)['ed25519'])), ' ', openssl_pkey_get_details($ed)['type'], "\n";
$p = hex2bin('ffffffffffffffffc90fdaa22168c234c4c6628b80dc1cd129024e088a67cc74020bbea63b139b22514a08798e3404ddef9519b3cd3a431b302b0a6df25f14374fe1356d6d51c245e485b576625e7ec6f44c42e9a63a3620ffffffffffffffff');
$da = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_DH, 'dh' => ['p' => $p, 'g' => "\2"]]);
$db = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_DH, 'dh' => ['p' => $p, 'g' => "\2"]]);
$dda = openssl_pkey_get_details($da)['dh'];
$ddb = openssl_pkey_get_details($db)['dh'];
echo implode(',', array_keys($dda)), ' ', openssl_pkey_get_details($da)['type'], ' ', openssl_pkey_get_details($da)['bits'], "\n";
var_dump($dda['p'] === $p, $dda['g'] === "\2");
$s1 = openssl_dh_compute_key($ddb['pub_key'], $da);
$s2 = openssl_dh_compute_key($dda['pub_key'], $db);
var_dump($s1 === $s2, strlen($s1));
var_dump(openssl_pkey_derive(openssl_pkey_get_public(openssl_pkey_get_details($db)['key']), $da) === $s1);
$dsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_DSA, 'private_key_bits' => 1024]);
echo implode(',', array_keys(openssl_pkey_get_details($dsa)['dsa'])), "\n";
var_dump(openssl_sign('dsa msg', $sg, $dsa, OPENSSL_ALGO_SHA256));
var_dump(openssl_verify('dsa msg', $sg, openssl_pkey_get_public(openssl_pkey_get_details($dsa)['key']), OPENSSL_ALGO_SHA256));
var_dump(openssl_verify('dsa msx', $sg, openssl_pkey_get_public(openssl_pkey_get_details($dsa)['key']), OPENSSL_ALGO_SHA256));

// certificate export / verify / key match / purpose
var_dump(openssl_x509_export($cert, $out));
var_dump(trim($out) === trim($cert));
var_dump(openssl_x509_export($cert, $txt, false));
var_dump(str_starts_with($txt, "Certificate:\n"), str_ends_with(trim($txt), '-----END CERTIFICATE-----'));
var_dump(openssl_x509_export('garbage', $junk));
$tmp = tempnam(sys_get_temp_dir(), 'mcx');
var_dump(openssl_x509_export_to_file($cert, $tmp), trim(file_get_contents($tmp)) === trim($cert));
var_dump(openssl_pkey_export_to_file($rsa, $tmp), str_starts_with(file_get_contents($tmp), '-----BEGIN PRIVATE KEY-----'));
var_dump(openssl_x509_verify($cert, openssl_pkey_get_details($k)['key']), openssl_x509_verify($cert, $pub2));
var_dump(openssl_x509_check_private_key($cert, $rsa), openssl_x509_check_private_key($cert, $e1));
file_put_contents($tmp, $cert);
foreach ([X509_PURPOSE_ANY, X509_PURPOSE_SSL_SERVER, X509_PURPOSE_SSL_CLIENT, X509_PURPOSE_SMIME_SIGN, X509_PURPOSE_CRL_SIGN] as $pu) {
    var_dump(openssl_x509_checkpurpose($cert, $pu, [$tmp]));
}
unlink($tmp);
openssl_x509_free(openssl_x509_read($cert));
openssl_pkey_free($e1);
echo "freed\n";
