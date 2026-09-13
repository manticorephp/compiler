<?php

// ext/openssl, the certificate-reading half — and it needs NO libcrypto. X.509
// and PKCS#10 are DER, a tag/length/value tree with a published grammar, so a
// fingerprint is a hash of the DER body and a subject is a walk of the Name.
// The fixtures are inline so the case carries no files and no `openssl` binary.

$cert = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIID7DCCAtSgAwIBAgIUEGwX8pk6Y3c/5AVJ5vorjELSHP8wDQYJKoZIhvcNAQEL
BQAwcDELMAkGA1UEBhMCVUExDTALBgNVBAgMBEt5aXYxEjAQBgNVBAoMCU1hbnRp
Y29yZTERMA8GA1UECwwIQ29tcGlsZXIxFTATBgNVBAMMDGV4YW1wbGUudGVzdDEU
MBIGCSqGSIb3DQEJARYFYUBiLmMwHhcNMjYwOTA5MDYxMjIxWhcNMzYwOTA2MDYx
MjIxWjBwMQswCQYDVQQGEwJVQTENMAsGA1UECAwES3lpdjESMBAGA1UECgwJTWFu
dGljb3JlMREwDwYDVQQLDAhDb21waWxlcjEVMBMGA1UEAwwMZXhhbXBsZS50ZXN0
MRQwEgYJKoZIhvcNAQkBFgVhQGIuYzCCASIwDQYJKoZIhvcNAQEBBQADggEPADCC
AQoCggEBAMaM/5XtdYCGY4rIplKTX8EeuB6V7MQt61T6lc22TbKRlqV6kqiK9590
zcMKN78zIV8T2zWtzlsMb3YaW0eFjxcYFOXTFhABk8bBEtu00LShdwYVBQdbWh83
fV3wrjk0L9ZF+mH6mcEUV+J9Q3Dj9ezYWNf1+bJ9sPtiGpKo9W0OJVlQtOKTeCrx
hzTulIyl6d+bo12ldOu9TgrfbwAQoFTe7h/pW6iWZy+dbuYveWLf2nPRJsp53Xrj
fxSa9lM4xgWAuavpoYNt3Oy9gas9fbEUPZOt9Zv9hX+lvhwMYEsJ0o6ZS1gi9KFv
6vXD5D5BFPx/GWH14UK6ECvJ2xnSgO8CAwEAAaN+MHwwHQYDVR0OBBYEFF+sQDSl
Dw2l6Dpwj1+AOCYULjthMB8GA1UdIwQYMBaAFF+sQDSlDw2l6Dpwj1+AOCYULjth
MA8GA1UdEwEB/wQFMAMBAf8wKQYDVR0RBCIwIIIMZXhhbXBsZS50ZXN0ghB3d3cu
ZXhhbXBsZS50ZXN0MA0GCSqGSIb3DQEBCwUAA4IBAQCGI+T1pVc7w4lrnmd4fAa9
cVIAZWiPH6erczkWr9vXsW4R55lF+gMGZb71LbQalZW04GtN324NKzYs7zG6HeS7
I4lmw7EgXFGNv3Tdp3IBQ3cs7XJyMEqGh+FbboaH+qwXfo4pHGc/qkY45gPd3miv
SW9SJJvDMYqnWtHGJB/XR/MzMLpJ2dGQHdTzCBzJRmOA5LbAHac85qLWZRvQM835
0eAQGbN5Ua0RSgM33rkbW4eqkavEolCQnawAClkEBWHQOgTAYYWLwbb+58HBSjgL
GgKakmBr3emSt11tgde0/va8VNLZ2W8seHyJRX63Bmi3PyfLKAj9yHL4X3FrLWBo
-----END CERTIFICATE-----
PEM;

$csr = <<<'PEM'
-----BEGIN CERTIFICATE REQUEST-----
MIICeTCCAWECAQAwNDELMAkGA1UEBhMCVUExEjAQBgNVBAoMCU1hbnRpY29yZTER
MA8GA1UEAwwIY3NyLnRlc3QwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIB
AQDGjP+V7XWAhmOKyKZSk1/BHrgelezELetU+pXNtk2ykZalepKoivefdM3DCje/
MyFfE9s1rc5bDG92GltHhY8XGBTl0xYQAZPGwRLbtNC0oXcGFQUHW1ofN31d8K45
NC/WRfph+pnBFFfifUNw4/Xs2FjX9fmyfbD7YhqSqPVtDiVZULTik3gq8Yc07pSM
penfm6NdpXTrvU4K328AEKBU3u4f6VuolmcvnW7mL3li39pz0SbKed16438UmvZT
OMYFgLmr6aGDbdzsvYGrPX2xFD2TrfWb/YV/pb4cDGBLCdKOmUtYIvShb+r1w+Q+
QRT8fxlh9eFCuhArydsZ0oDvAgMBAAGgADANBgkqhkiG9w0BAQsFAAOCAQEABJtO
XshRNAdf4CA9sZiHoyj24BL6x3JhLTkYIgfFWYGVfaZsHTo7mo3du5TsoHIb4psR
qcYtFFzfrg45Jq335dQKKzCuYUKXiRP1+zRHH+hhpCp56pM3Zf5MXUAG/LU8MR3b
NlPLG1npAtWgfzloBXx4dy/seLS8gUtpSHgxDuObU8sAaTjuBPbqyEZ3sPWsQ1r3
UdZEQ+tsbBpjGACH5xhR5wCbETZKYrPJRZbk0I2bgI61Ksl17EVngQ1l4aSvTiY6
7hJuXQGzaX2TmawpLk96xnhQ0pXbkc0OUcfjRKmOPiYKk2DmDJzeRG4F7/f1qqhW
J0PPPYDJ7XVObwA8mw==
-----END CERTIFICATE REQUEST-----
PEM;

$dup = <<<'PEM'
-----BEGIN CERTIFICATE REQUEST-----
MIICgTCCAWkCAQAwPDELMAkGA1UEBhMCVUExDDAKBgNVBAsMA09uZTEMMAoGA1UE
CwwDVHdvMREwDwYDVQQDDAhkdXAudGVzdDCCASIwDQYJKoZIhvcNAQEBBQADggEP
ADCCAQoCggEBAMaM/5XtdYCGY4rIplKTX8EeuB6V7MQt61T6lc22TbKRlqV6kqiK
9590zcMKN78zIV8T2zWtzlsMb3YaW0eFjxcYFOXTFhABk8bBEtu00LShdwYVBQdb
Wh83fV3wrjk0L9ZF+mH6mcEUV+J9Q3Dj9ezYWNf1+bJ9sPtiGpKo9W0OJVlQtOKT
eCrxhzTulIyl6d+bo12ldOu9TgrfbwAQoFTe7h/pW6iWZy+dbuYveWLf2nPRJsp5
3XrjfxSa9lM4xgWAuavpoYNt3Oy9gas9fbEUPZOt9Zv9hX+lvhwMYEsJ0o6ZS1gi
9KFv6vXD5D5BFPx/GWH14UK6ECvJ2xnSgO8CAwEAAaAAMA0GCSqGSIb3DQEBCwUA
A4IBAQBf7zz2KF3oKIlMCvHmpy9KqqAq7qMxGBsc4ctRnIwhhseQDx9Uj0P+9TYF
MW8MHpYfXrEeBV9/Y1rMEKqBGdLvsGA2FBqlwc9QEzJcsV73yqkaRV4cxbK9f6mi
a4kCZq/746pEOxzN38RDSHADBnkRAl/jXs9AtXNJnOcOJ89HQ56WWUmm/VcLPuu2
nUCT6cVaS1pr6xLveHBxov7KRVQwpMiT0EDinWDH8C20NEmmrSqyuYrbElC+IV1A
1Fe0kpUZkCaS8ozzh1jHRnqyXNRgA6QGvmht/9M5VVEmbA6fK9OHhMoStKGk6ojn
MwkKZ6l4BVcoF6PuhojZ1VHH4hTR
-----END CERTIFICATE REQUEST-----
PEM;

// A fingerprint identifies the ENCODED certificate, so nothing has to
// understand its contents.
var_dump(openssl_x509_fingerprint($cert));
var_dump(openssl_x509_fingerprint($cert, 'sha256'));
var_dump(openssl_x509_fingerprint($cert, 'md5'));
var_dump(bin2hex(openssl_x509_fingerprint($cert, 'sha256', true)));

// A DN component that appears twice becomes a LIST under its key — php's own
// shape, and the reason the walk cannot simply assign.
var_dump(openssl_csr_get_subject($csr));
var_dump(openssl_csr_get_subject($dup));

$pk = openssl_pkey_get_public($cert);
var_dump(get_class($pk));
$d = openssl_pkey_get_details($pk);
echo implode(',', array_keys($d)), "\n";
echo $d['bits'], ' ', $d['type'], ' ', bin2hex($d['rsa']['e']), ' ', strlen($d['rsa']['n']), "\n";
// The modulus comes back with its DER sign byte stripped: 256 bytes for a
// 2048-bit key, not 257.
echo bin2hex(substr($d['rsa']['n'], 0, 8)), '..', bin2hex(substr($d['rsa']['n'], -8)), "\n";
// The re-encoded PEM is byte-for-byte what openssl emits, and feeding it back
// in yields the same key.
echo $d['key'];
var_dump(openssl_pkey_get_details(openssl_pkey_get_public($d['key'])) == $d);

// php WARNS on a blob that is not a certificate and returns false; this build
// returns false silently — the documented no-warnings divergence, so only the
// return values are compared here.
var_dump(openssl_pkey_get_public('garbage'));
var_dump(openssl_x509_fingerprint('garbage'));
var_dump(openssl_csr_get_subject('garbage'));
var_dump(openssl_x509_fingerprint($cert, 'nosuchalgo'));
var_dump(openssl_csr_get_subject($cert));
