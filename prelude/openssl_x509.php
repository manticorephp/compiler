<?php

/**
 * ext/openssl, the certificate-reading half — and it needs NO libcrypto.
 *
 * X.509 and PKCS#10 are DER: a tag/length/value tree with a published grammar.
 * Everything below reads that tree directly, so a fingerprint is a hash of the
 * DER body and a subject is a walk of the Name, with no host library and no new
 * FFI symbol. The `Runtime\Openssl` binding next door stays what it is — the TLS
 * TRANSPORT — and gains nothing here it would have had to grow: `d2i_X509` alone
 * wants a pointer-to-pointer out-parameter, and every X509_NAME / ASN1_STRING /
 * BIO accessor after it would be a fresh bind.
 *
 * What is deliberately NOT here is `openssl_x509_parse`. Its array carries
 * `purposes` and `extensions`, and those are not facts in the certificate — they
 * are OpenSSL's own policy verdicts and its human-readable renderings of each
 * extension. Reproducing that text is matching an implementation, not a format,
 * and it is the one thing in this family a binding really would answer better.
 */

/** The DER bytes of a PEM block with $label, or '' when there is no such block. */
function __mc_pem_der(string $pem, string $label): string
{
    $begin = '-----BEGIN ' . $label . '-----';
    $end = '-----END ' . $label . '-----';
    $i = \strpos($pem, $begin);
    if ($i === false) { return ''; }
    $i = $i + \strlen($begin);
    $j = \strpos($pem, $end, $i);
    if ($j === false) { return ''; }
    $b = \base64_decode(\str_replace(["\r", "\n", ' ', "\t"], ['', '', '', ''], \substr($pem, $i, $j - $i)), true);

    return $b === false ? '' : $b;
}

/**
 * One DER element at $off: `[tag, contentStart, contentLength, nextOffset]`, or
 * `[]` when the bytes do not form one. Long-form lengths carry their byte count
 * in the low seven bits of the first length octet; anything wider than four
 * bytes is refused rather than silently truncated.
 *
 * @return int[]
 */
function __mc_der_tlv(string $d, int $off): array
{
    $n = \strlen($d);
    if ($off + 2 > $n) { return []; }
    $tag = \ord($d[$off]);
    $p = $off + 1;
    $l = \ord($d[$p]);
    $p = $p + 1;
    if ($l > 0x80) {
        $cnt = $l & 0x7F;
        if ($cnt > 4 || $p + $cnt > $n) { return []; }
        $l = 0;
        for ($i = 0; $i < $cnt; $i = $i + 1) {
            $l = ($l << 8) | \ord($d[$p + $i]);
        }
        $p = $p + $cnt;
    } elseif ($l === 0x80) {
        return [];              // indefinite length is BER, not DER
    }
    if ($p + $l > $n) { return []; }

    return [$tag, $p, $l, $p + $l];
}

/** An OBJECT IDENTIFIER's content bytes as its dotted decimal text. */
function __mc_der_oid(string $d, int $start, int $len): string
{
    if ($len < 1) { return ''; }
    $b = \ord($d[$start]);
    $out = \intdiv($b, 40) . '.' . ($b % 40);
    $v = 0;
    for ($i = 1; $i < $len; $i = $i + 1) {
        $c = \ord($d[$start + $i]);
        $v = ($v << 7) | ($c & 0x7F);
        if (($c & 0x80) === 0) {
            $out = $out . '.' . $v;
            $v = 0;
        }
    }

    return $out;
}

/** The short name OpenSSL prints for a DN attribute OID; the OID itself if unknown. */
function __mc_oid_sn(string $oid): string
{
    if ($oid === '2.5.4.3') { return 'CN'; }
    if ($oid === '2.5.4.6') { return 'C'; }
    if ($oid === '2.5.4.7') { return 'L'; }
    if ($oid === '2.5.4.8') { return 'ST'; }
    if ($oid === '2.5.4.9') { return 'street'; }
    if ($oid === '2.5.4.10') { return 'O'; }
    if ($oid === '2.5.4.11') { return 'OU'; }
    if ($oid === '2.5.4.4') { return 'SN'; }
    if ($oid === '2.5.4.5') { return 'serialNumber'; }
    if ($oid === '2.5.4.12') { return 'title'; }
    if ($oid === '2.5.4.13') { return 'description'; }
    if ($oid === '2.5.4.15') { return 'businessCategory'; }
    if ($oid === '2.5.4.17') { return 'postalCode'; }
    if ($oid === '2.5.4.42') { return 'GN'; }
    if ($oid === '2.5.4.43') { return 'initials'; }
    if ($oid === '2.5.4.46') { return 'dnQualifier'; }
    if ($oid === '1.2.840.113549.1.9.1') { return 'emailAddress'; }
    if ($oid === '0.9.2342.19200300.100.1.1') { return 'UID'; }
    if ($oid === '0.9.2342.19200300.100.1.25') { return 'DC'; }

    return $oid;
}

/**
 * A Name (a SEQUENCE of RDN SETs) as php's map. A component that appears twice
 * becomes a LIST under its key — php's own shape for a DN with two OUs, and the
 * reason this cannot simply assign.
 *
 * @return array<string,mixed>
 */
function __mc_der_name(string $d, int $start, int $len): array
{
    /** @var array<string,mixed> $out */
    $out = [];
    $p = $start;
    $endAll = $start + $len;
    while ($p < $endAll) {
        $set = \__mc_der_tlv($d, $p);
        if (\count($set) === 0) { break; }
        $q = $set[1];
        $setEnd = $set[1] + $set[2];
        while ($q < $setEnd) {
            $pair = \__mc_der_tlv($d, $q);
            if (\count($pair) === 0) { break; }
            $oidT = \__mc_der_tlv($d, $pair[1]);
            if (\count($oidT) === 0) { break; }
            $valT = \__mc_der_tlv($d, $oidT[3]);
            if (\count($valT) === 0) { break; }
            $k = \__mc_oid_sn(\__mc_der_oid($d, $oidT[1], $oidT[2]));
            $v = \substr($d, $valT[1], $valT[2]);
            if (isset($out[$k])) {
                $cur = $out[$k];
                if (\is_array($cur)) {
                    $cur[] = $v;
                    $out[$k] = $cur;
                } else {
                    $out[$k] = [$cur, $v];
                }
            } else {
                $out[$k] = $v;
            }
            $q = $pair[3];
        }
        $p = $set[3];
    }

    return $out;
}

/**
 * An opaque public-key handle. php's is an internal class with no visible
 * state; this one carries the SubjectPublicKeyInfo DER it was built from, which
 * is everything openssl_pkey_get_details answers from.
 */
class OpenSSLAsymmetricKey
{
    public string $spki = '';

    public function __construct(string $spki)
    {
        $this->spki = $spki;
    }
}

/**
 * `openssl_x509_fingerprint($cert, $algo, $binary)` — the digest of the DER
 * body. That is the whole definition: a fingerprint identifies the encoded
 * certificate, so nothing has to understand its contents.
 *
 * php WARNS and returns false for a blob that is not a certificate; this build
 * returns false silently, the documented no-warnings divergence.
 *
 * An unknown $digest_algo answers FALSE and does not throw. That is php's
 * behaviour here and it is NOT hash()'s: `hash('nosuch', …)` raises a
 * ValueError, and openssl_x509_fingerprint absorbs it into a false. Checking
 * the name against hash_algos() rather than catching keeps the two functions'
 * supported sets in step by construction.
 *
 * @return string|false
 */
function openssl_x509_fingerprint(string $certificate, string $digest_algo = 'sha1', bool $binary = false)
{
    if (!\in_array($digest_algo, \hash_algos(), true)) { return false; }
    $der = \__mc_pem_der($certificate, 'CERTIFICATE');
    if ($der === '') { return false; }

    return \hash($digest_algo, $der, $binary);
}

/**
 * `openssl_csr_get_subject($csr)` — the subject of a PKCS#10 request.
 * CertificationRequestInfo is `{ version, subject, SPKI, [0] attributes }`, so
 * the subject is the second element of the first element.
 *
 * @return array<string,mixed>|false
 */
function openssl_csr_get_subject(string $csr, bool $short_names = true)
{
    $der = \__mc_pem_der($csr, 'CERTIFICATE REQUEST');
    if ($der === '') { $der = \__mc_pem_der($csr, 'NEW CERTIFICATE REQUEST'); }
    if ($der === '') { return false; }
    $outer = \__mc_der_tlv($der, 0);
    if (\count($outer) === 0) { return false; }
    $cri = \__mc_der_tlv($der, $outer[1]);
    if (\count($cri) === 0) { return false; }
    $ver = \__mc_der_tlv($der, $cri[1]);
    if (\count($ver) === 0) { return false; }
    $subj = \__mc_der_tlv($der, $ver[3]);
    if (\count($subj) === 0) { return false; }

    return \__mc_der_name($der, $subj[1], $subj[2]);
}

/**
 * The SubjectPublicKeyInfo DER inside a certificate, or '' if there is none.
 * TBSCertificate is `{ [0] version, serial, sigAlg, issuer, validity, subject,
 * SPKI, … }` — seven elements in, and the optional version tag is what makes
 * the count conditional rather than fixed.
 */
function __mc_x509_spki(string $der): string
{
    $outer = \__mc_der_tlv($der, 0);
    if (\count($outer) === 0) { return ''; }
    $tbs = \__mc_der_tlv($der, $outer[1]);
    if (\count($tbs) === 0) { return ''; }
    $p = $tbs[1];
    $first = \__mc_der_tlv($der, $p);
    if (\count($first) === 0) { return ''; }
    // [0] EXPLICIT version is context-specific constructed (0xA0); without it
    // the sequence starts at serialNumber and everything shifts by one.
    $skip = $first[0] === 0xA0 ? 6 : 5;
    for ($i = 0; $i < $skip; $i = $i + 1) {
        $e = \__mc_der_tlv($der, $p);
        if (\count($e) === 0) { return ''; }
        $p = $e[3];
    }
    $spki = \__mc_der_tlv($der, $p);
    if (\count($spki) === 0 || $spki[0] !== 0x30) { return ''; }

    return \substr($der, $p, $spki[3] - $p);
}

/**
 * `openssl_pkey_get_public($public_key)` — accepts a certificate PEM or a bare
 * PUBLIC KEY PEM, as php does, and answers an opaque handle.
 *
 * @return OpenSSLAsymmetricKey|false
 */
function openssl_pkey_get_public(string $public_key)
{
    $spki = \__mc_pem_der($public_key, 'PUBLIC KEY');
    if ($spki === '') {
        $cert = \__mc_pem_der($public_key, 'CERTIFICATE');
        if ($cert === '') { return false; }
        $spki = \__mc_x509_spki($cert);
    }
    if ($spki === '') { return false; }
    $t = \__mc_der_tlv($spki, 0);
    if (\count($t) === 0 || $t[0] !== 0x30) { return false; }

    return new OpenSSLAsymmetricKey($spki);
}

/** DER wrapped as a PEM block, base64 in 64-character lines. */
function __mc_der_pem(string $der, string $label): string
{
    $b = \base64_encode($der);
    $out = '-----BEGIN ' . $label . "-----\n";
    $n = \strlen($b);
    for ($i = 0; $i < $n; $i = $i + 64) {
        $out = $out . \substr($b, $i, 64) . "\n";
    }

    return $out . '-----END ' . $label . "-----\n";
}

/**
 * `openssl_pkey_get_details($key)` — php's four keys, in php's order:
 * bits, key, rsa, type. The modulus comes back with its DER sign byte stripped,
 * which is why `n` is 256 bytes for a 2048-bit key and not 257.
 *
 * Only RSA is answered; an EC or DSA key returns false rather than a wrong
 * shape, because `type` and the algorithm sub-array would both be lies.
 *
 * @return array<string,mixed>|false
 */
function openssl_pkey_get_details(OpenSSLAsymmetricKey $key)
{
    $d = $key->spki;
    $outer = \__mc_der_tlv($d, 0);
    if (\count($outer) === 0) { return false; }
    $alg = \__mc_der_tlv($d, $outer[1]);
    if (\count($alg) === 0) { return false; }
    $algOid = \__mc_der_tlv($d, $alg[1]);
    if (\count($algOid) === 0) { return false; }
    if (\__mc_der_oid($d, $algOid[1], $algOid[2]) !== '1.2.840.113549.1.1.1') { return false; }
    $bitstr = \__mc_der_tlv($d, $alg[3]);
    if (\count($bitstr) === 0 || $bitstr[0] !== 0x03) { return false; }
    // A BIT STRING's first content byte counts the unused trailing bits; the
    // key sequence starts after it.
    $seq = \__mc_der_tlv($d, $bitstr[1] + 1);
    if (\count($seq) === 0 || $seq[0] !== 0x30) { return false; }
    $nT = \__mc_der_tlv($d, $seq[1]);
    if (\count($nT) === 0) { return false; }
    $eT = \__mc_der_tlv($d, $nT[3]);
    if (\count($eT) === 0) { return false; }
    $n = \substr($d, $nT[1], $nT[2]);
    while (\strlen($n) > 1 && \ord($n[0]) === 0) { $n = \substr($n, 1); }
    $e = \substr($d, $eT[1], $eT[2]);
    while (\strlen($e) > 1 && \ord($e[0]) === 0) { $e = \substr($e, 1); }
    // The bit length is the modulus's, counted from its top set bit.
    $bits = (\strlen($n) - 1) * 8;
    $top = \ord($n[0]);
    while ($top > 0) {
        $bits = $bits + 1;
        $top = $top >> 1;
    }
    /** @var array<string,mixed> $out */
    $out = [];
    $out['bits'] = $bits;
    $out['key'] = \__mc_der_pem($d, 'PUBLIC KEY');
    $out['rsa'] = ['n' => $n, 'e' => $e];
    $out['type'] = 0;

    return $out;
}
