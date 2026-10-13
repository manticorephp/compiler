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
#[\Manticore\Attr\Uncomparable]
class OpenSSLAsymmetricKey
{
    public string $spki = '';
    /** Unencrypted PKCS#8 PEM, or '' for a public-only key. */
    public string $priv = '';

    public function __construct(string $spki, string $priv = '')
    {
        $this->spki = $spki;
        $this->priv = $priv;
    }
}

/** An X.509 certificate handle; carries the PEM it was read from. */
#[\Manticore\Attr\Uncomparable]
class OpenSSLCertificate
{
    public string $pem = '';

    public function __construct(string $pem)
    {
        $this->pem = $pem;
    }
}

/** A PKCS#10 certificate request handle; carries the PEM it was read from. */
#[\Manticore\Attr\Uncomparable]
class OpenSSLCertificateSigningRequest
{
    public string $pem = '';

    public function __construct(string $pem)
    {
        $this->pem = $pem;
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
function openssl_x509_fingerprint(OpenSSLCertificate|string $certificate, string $digest_algo = 'sha1', bool $binary = false)
{
    if (!\in_array($digest_algo, \hash_algos(), true)) { return false; }
    $der = \__mc_pem_der($certificate instanceof OpenSSLCertificate ? $certificate->pem : $certificate, 'CERTIFICATE');
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
function openssl_csr_get_subject(OpenSSLCertificateSigningRequest|string $csr, bool $short_names = true)
{
    if ($csr instanceof OpenSSLCertificateSigningRequest) { $csr = $csr->pem; }
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
 * `openssl_pkey_get_public($public_key)` — a key object, a certificate, a PUBLIC KEY
 * PEM or a private key (its public half), as php accepts; answers an opaque handle.
 *
 * @return OpenSSLAsymmetricKey|false
 */
function openssl_pkey_get_public(mixed $public_key)
{
    if ($public_key instanceof OpenSSLAsymmetricKey) { return new OpenSSLAsymmetricKey($public_key->spki); }
    $pem = \__mc_ossl_pub_of($public_key);
    if ($pem === '') { return false; }
    $der = \__mc_pem_der(\__mc_ossl_spki_pem($pem), 'PUBLIC KEY');
    if ($der === '') { return false; }

    return new OpenSSLAsymmetricKey($der);
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
 * `openssl_pkey_get_details($key)` — bits, key, the per-type array (rsa / dsa / dh /
 * ec / ed25519 / ...) and type, in php's order. A public-only handle answers the
 * public half of the parameters.
 *
 * @return array<string,mixed>|false
 */
function openssl_pkey_get_details(OpenSSLAsymmetricKey $key)
{
    if ($key->priv !== '') { return \__mc_ossl_pkey_details($key->priv, true); }

    return \__mc_ossl_pkey_details(\__mc_der_pem($key->spki, 'PUBLIC KEY'), false);
}

// ── libcrypto-backed half: keys, signatures, raw RSA, certificate parse ──────
// The work is in src/Runtime/Stdlib/Openssl.php (`__mc_ossl_*`); this file owns
// the two handle classes, so every function that takes or returns one lives here.

/** Text of a key / certificate argument: `file://path` is read, anything else is the text itself. */
function __mc_ossl_arg_text(string $s): string
{
    if (\str_starts_with($s, 'file://')) {
        $c = \file_get_contents(\substr($s, 7));

        return $c === false ? '' : $c;
    }

    return $s;
}

/** Unencrypted PKCS#8 PEM of a private-key argument (key, PEM, file://, [key, passphrase]); '' when unusable. */
function __mc_ossl_priv_of(mixed $key, ?string $pass = null): string
{
    if ($key instanceof OpenSSLAsymmetricKey) { return $key->priv; }
    if (\is_array($key)) {
        if (\count($key) !== 2) { return ''; }
        $p = $key[1];

        return \__mc_ossl_priv_of($key[0], \is_string($p) ? $p : null);
    }
    if (\is_string($key)) { return \__mc_ossl_priv_pem(\__mc_ossl_arg_text($key), $pass ?? ''); }

    return '';
}

/** A PEM the libcrypto loaders read as a public key, from a key / certificate / PEM argument; '' when unusable. */
function __mc_ossl_pub_of(mixed $key): string
{
    if ($key instanceof OpenSSLAsymmetricKey) { return \__mc_der_pem($key->spki, 'PUBLIC KEY'); }
    if ($key instanceof OpenSSLCertificate) { return $key->pem; }
    if (\is_array($key)) { return \count($key) === 0 ? '' : \__mc_ossl_pub_of($key[0]); }
    if (\is_string($key)) { return \__mc_ossl_spki_pem(\__mc_ossl_arg_text($key)) === '' ? '' : \__mc_ossl_arg_text($key); }

    return '';
}

/** Digest name for an OPENSSL_ALGO_* int or a digest name. */
function __mc_ossl_algo_of(string $fn, mixed $algorithm): string
{
    if (\is_string($algorithm)) { return (string)$algorithm; }

    return \__mc_ossl_algo_name($fn, (int)$algorithm);
}

function __mc_ossl_key_object(string $priv): OpenSSLAsymmetricKey
{
    return new OpenSSLAsymmetricKey(\__mc_pem_der(\__mc_ossl_spki_pem($priv), 'PUBLIC KEY'), $priv);
}

/**
 * `openssl_pkey_new($options)` — RSA (default, 2048 bits) or EC (`curve_name`).
 * DSA / DH / Ed25519 are not generated here and throw.
 *
 * @param array<string,mixed>|null $options
 * @return OpenSSLAsymmetricKey|false
 */
function openssl_pkey_new(?array $options = null)
{
    $type = 0;
    $bits = 2048;
    $curve = '';
    $dhP = '';
    $dhG = '';
    if ($options !== null) {
        if (isset($options['private_key_type'])) { $type = (int)$options['private_key_type']; }
        if (isset($options['private_key_bits'])) { $bits = (int)$options['private_key_bits']; }
        if (isset($options['curve_name'])) { $curve = (string)$options['curve_name']; }
        if (isset($options['dh'])) {
            $dh = $options['dh'];
            if (\is_array($dh)) {
                if (isset($dh['p'])) { $dhP = (string)$dh['p']; }
                if (isset($dh['g'])) { $dhG = (string)$dh['g']; }
            }
        }
    }
    if ($type === 0 && $bits < 384) {
        throw new \ValueError('openssl_pkey_new(): Private key length must be at least 384 bits, ' . $bits . ' given');
    }
    $priv = \__mc_ossl_pkey_new($type, $bits, $curve, $dhP, $dhG);
    if ($priv === '') { return false; }

    return \__mc_ossl_key_object($priv);
}

/** @return OpenSSLAsymmetricKey|false */
function openssl_pkey_get_private(mixed $private_key, ?string $passphrase = null)
{
    $priv = \__mc_ossl_priv_of($private_key, $passphrase);
    if ($priv === '') { return false; }

    return \__mc_ossl_key_object($priv);
}

/** @param array<string,mixed>|null $options */
function openssl_pkey_export(mixed $key, #[\Manticore\Attr\RefOut] ?string &$output, ?string $passphrase = null, ?array $options = null): bool
{
    $priv = \__mc_ossl_priv_of($key, null);
    if ($priv === '') { return false; }
    $pem = \__mc_ossl_priv_export($priv, $passphrase === null ? '' : $passphrase);
    if ($pem === '') { return false; }
    $output = $pem;

    return true;
}

function openssl_sign(string $data, #[\Manticore\Attr\RefOut] ?string &$signature, mixed $private_key, mixed $algorithm = 1): bool
{
    $priv = \__mc_ossl_priv_of($private_key, null);
    if ($priv === '') {
        throw new \ValueError('openssl_sign(): Argument #3 ($private_key) must be a valid private key');
    }
    $sig = \__mc_ossl_sign($data, $priv, \__mc_ossl_algo_of('openssl_sign', $algorithm));
    if ($sig === false) { return false; }
    $signature = (string)$sig;

    return true;
}

function openssl_verify(string $data, string $signature, mixed $public_key, mixed $algorithm = 1): int|false
{
    $pub = \__mc_ossl_pub_of($public_key);
    if ($pub === '') {
        throw new \ValueError('openssl_verify(): Argument #3 ($public_key) must be a valid public key');
    }

    return \__mc_ossl_verify($data, $signature, $pub, \__mc_ossl_algo_of('openssl_verify', $algorithm));
}

/** RSA op $op (0 pub-enc, 1 priv-dec, 2 priv-enc, 3 pub-dec) behind the four php entry points. */
function __mc_ossl_rsa_call(string $fn, int $op, string $data, mixed $key, int $padding): string|false
{
    if ($op === 1 || $op === 2) {
        $pem = \__mc_ossl_priv_of($key, null);
        $what = 'private_key';
    } else {
        $pem = \__mc_ossl_pub_of($key);
        $what = 'public_key';
    }
    if ($pem === '') {
        throw new \ValueError($fn . '(): Argument #3 ($' . $what . ') must be a valid key');
    }

    return \__mc_ossl_rsa($op, $data, $pem, $padding);
}

function openssl_public_encrypt(string $data, #[\Manticore\Attr\RefOut] ?string &$encrypted_data, mixed $public_key, int $padding = 1): bool
{
    $r = \__mc_ossl_rsa_call('openssl_public_encrypt', 0, $data, $public_key, $padding);
    if ($r === false) { return false; }
    $encrypted_data = (string)$r;

    return true;
}

function openssl_private_decrypt(string $data, #[\Manticore\Attr\RefOut] ?string &$decrypted_data, mixed $private_key, int $padding = 1): bool
{
    $r = \__mc_ossl_rsa_call('openssl_private_decrypt', 1, $data, $private_key, $padding);
    if ($r === false) { return false; }
    $decrypted_data = (string)$r;

    return true;
}

function openssl_private_encrypt(string $data, #[\Manticore\Attr\RefOut] ?string &$encrypted_data, mixed $private_key, int $padding = 1): bool
{
    $r = \__mc_ossl_rsa_call('openssl_private_encrypt', 2, $data, $private_key, $padding);
    if ($r === false) { return false; }
    $encrypted_data = (string)$r;

    return true;
}

function openssl_public_decrypt(string $data, #[\Manticore\Attr\RefOut] ?string &$decrypted_data, mixed $public_key, int $padding = 1): bool
{
    $r = \__mc_ossl_rsa_call('openssl_public_decrypt', 3, $data, $public_key, $padding);
    if ($r === false) { return false; }
    $decrypted_data = (string)$r;

    return true;
}

/** @return OpenSSLCertificate|false */
function openssl_x509_read(OpenSSLCertificate|string $certificate)
{
    if ($certificate instanceof OpenSSLCertificate) { return $certificate; }
    $pem = \__mc_ossl_arg_text($certificate);
    if (!\__mc_ossl_x509_valid($pem)) { return false; }

    return new OpenSSLCertificate($pem);
}

/** @return array<string,mixed>|false */
function openssl_x509_parse(OpenSSLCertificate|string $certificate, bool $short_names = true)
{
    $pem = $certificate instanceof OpenSSLCertificate ? $certificate->pem : \__mc_ossl_arg_text($certificate);

    return \__mc_ossl_x509_parse($pem, $short_names);
}
/** @return OpenSSLAsymmetricKey|false */
function openssl_get_publickey(mixed $public_key)
{
    return \openssl_pkey_get_public($public_key);
}

/** @return OpenSSLAsymmetricKey|false */
function openssl_get_privatekey(mixed $private_key, ?string $passphrase = null)
{
    return \openssl_pkey_get_private($private_key, $passphrase);
}

/** No-op since php 8.0: handles are objects and freed with their last reference. */
function openssl_pkey_free(OpenSSLAsymmetricKey $key): void
{
}

/** No-op since php 8.0. */
function openssl_x509_free(OpenSSLCertificate $certificate): void
{
}

/** @param array<string,mixed>|null $options */
function openssl_pkey_export_to_file(mixed $key, string $output_filename, ?string $passphrase = null, ?array $options = null): bool
{
    $priv = \__mc_ossl_priv_of($key, null);
    if ($priv === '') { return false; }
    $pem = \__mc_ossl_priv_export($priv, $passphrase === null ? '' : $passphrase);
    if ($pem === '') { return false; }

    return \file_put_contents($output_filename, $pem) !== false;
}

function openssl_x509_export(OpenSSLCertificate|string $certificate, #[\Manticore\Attr\RefOut] ?string &$output, bool $no_text = true): bool
{
    $pem = $certificate instanceof OpenSSLCertificate ? $certificate->pem : \__mc_ossl_arg_text($certificate);
    $out = \__mc_ossl_x509_export($pem, $no_text);
    if ($out === '') { return false; }
    $output = $out;

    return true;
}

function openssl_x509_export_to_file(OpenSSLCertificate|string $certificate, string $output_filename, bool $no_text = true): bool
{
    $pem = $certificate instanceof OpenSSLCertificate ? $certificate->pem : \__mc_ossl_arg_text($certificate);
    $out = \__mc_ossl_x509_export($pem, $no_text);
    if ($out === '') { return false; }

    return \file_put_contents($output_filename, $out) !== false;
}

/** 1 when the certificate was signed by the key, 0 when not, -1 on error. */
function openssl_x509_verify(OpenSSLCertificate|string $certificate, mixed $public_key): int
{
    $pub = \__mc_ossl_pub_of($public_key);
    if ($pub === '') {
        throw new \ValueError('openssl_x509_verify(): Argument #2 ($public_key) must be a valid public key');
    }
    $pem = $certificate instanceof OpenSSLCertificate ? $certificate->pem : \__mc_ossl_arg_text($certificate);

    return \__mc_ossl_x509_verify($pem, $pub);
}

function openssl_x509_check_private_key(OpenSSLCertificate|string $certificate, mixed $private_key): bool
{
    $priv = \__mc_ossl_priv_of($private_key, null);
    if ($priv === '') {
        throw new \ValueError('openssl_x509_check_private_key(): Argument #2 ($private_key) must be a valid private key');
    }
    $pem = $certificate instanceof OpenSSLCertificate ? $certificate->pem : \__mc_ossl_arg_text($certificate);

    return \__mc_ossl_x509_check_key($pem, $priv);
}

/**
 * @param string[] $ca_info files or directories of trusted certificates
 * @return bool|int true when verified for the purpose, false when rejected, -1 on error
 */
function openssl_x509_checkpurpose(OpenSSLCertificate|string $certificate, int $purpose, array $ca_info = [], ?string $untrusted_certificates_file = null)
{
    $pem = $certificate instanceof OpenSSLCertificate ? $certificate->pem : \__mc_ossl_arg_text($certificate);
    $extra = '';
    if ($untrusted_certificates_file !== null) {
        $c = \file_get_contents($untrusted_certificates_file);
        if ($c === false) { return -1; }
        $extra = $c;
    }
    $r = \__mc_ossl_x509_checkpurpose($pem, $purpose, $ca_info, $extra);

    return $r < 0 ? -1 : $r === 1;
}

/** Shared secret of two keys (ECDH / X25519 / DH). */
function openssl_pkey_derive(mixed $public_key, mixed $private_key, int $key_length = 0): string|false
{
    $pub = \__mc_ossl_pub_of($public_key);
    $priv = \__mc_ossl_priv_of($private_key, null);
    if ($pub === '' || $priv === '') {
        throw new \ValueError('openssl_pkey_derive(): Argument #' . ($pub === '' ? '1 ($public_key)' : '2 ($private_key)') . ' must be a valid key');
    }
    $r = \__mc_ossl_derive($priv, $pub);
    if ($r === false) { return false; }
    $r = (string)$r;
    if ($key_length > 0 && $key_length < \strlen($r)) { return \substr($r, 0, $key_length); }

    return $r;
}

/** DH shared secret of the raw public value $public_key with the DH key $private_key. */
function openssl_dh_compute_key(string $public_key, OpenSSLAsymmetricKey $private_key): string|false
{
    $outer = \__mc_der_tlv($private_key->spki, 0);
    if (\count($outer) === 0) { return false; }
    $alg = \__mc_der_tlv($private_key->spki, $outer[1]);
    if (\count($alg) === 0) { return false; }
    // Same AlgorithmIdentifier (the DH parameters), the peer's value as the key.
    $algRaw = \substr($private_key->spki, $outer[1], $alg[3] - $outer[1]);
    $bits = \__mc_ossl_der(3, "\0" . \__mc_ossl_der_uint($public_key));
    $peer = \__mc_ossl_pem(\__mc_ossl_der(0x30, $algRaw . $bits), 'PUBLIC KEY');
    if ($private_key->priv === '') { return false; }
    $r = \__mc_ossl_derive($private_key->priv, $peer);

    return $r === false ? false : (string)$r;
}

// ── CSR ───────────────────────────────────────────────────────────────────

/** Reject the options that need an openssl.cnf, which this build has no parser for. @param array<string,mixed> $options */
function __mc_ossl_no_config(string $fn, array $options): void
{
    foreach (['config', 'req_extensions', 'x509_extensions'] as $k) {
        if (isset($options[$k])) {
            throw new \ValueError($fn . '(): the "' . $k . '" option is not supported (no openssl.cnf support)');
        }
    }
}

function __mc_ossl_csr_pem(OpenSSLCertificateSigningRequest|string $csr): string
{
    return $csr instanceof OpenSSLCertificateSigningRequest ? $csr->pem : \__mc_ossl_arg_text($csr);
}

/**
 * `openssl_csr_new($dn, &$private_key, $options)` — signs a request for $dn with the
 * given key, or with a fresh one (RSA 2048 unless `private_key_bits` / `private_key_type`
 * / `curve_name` say otherwise) that is stored back into $private_key.
 *
 * @param array<string,mixed> $distinguished_names
 * @param array<string,mixed>|null $options
 * @param array<string,mixed>|null $extra_attributes
 * @return OpenSSLCertificateSigningRequest|false
 */
function openssl_csr_new(array $distinguished_names, mixed &$private_key, ?array $options = null, ?array $extra_attributes = null)
{
    if ($options !== null) { \__mc_ossl_no_config('openssl_csr_new', $options); }
    if ($extra_attributes !== null && \count($extra_attributes) > 0) {
        throw new \ValueError('openssl_csr_new(): Argument #4 ($extra_attributes) is not supported');
    }
    $priv = $private_key === null ? '' : \__mc_ossl_priv_of($private_key, null);
    if ($priv === '') {
        $k = \openssl_pkey_new($options);
        if ($k === false) { return false; }
        $private_key = $k;
        $priv = $k->priv;
    }
    $digest = 'sha256';
    if ($options !== null && isset($options['digest_alg'])) { $digest = (string)$options['digest_alg']; }
    /** @var array<string,string> $dn */
    $dn = [];
    foreach ($distinguished_names as $name => $value) { $dn[(string)$name] = (string)$value; }
    $pem = \__mc_ossl_csr_new($dn, $priv, $digest);
    if ($pem === '') { return false; }

    return new OpenSSLCertificateSigningRequest($pem);
}

function openssl_csr_export(OpenSSLCertificateSigningRequest|string $csr, #[\Manticore\Attr\RefOut] ?string &$output, bool $no_text = true): bool
{
    $out = \__mc_ossl_csr_export(\__mc_ossl_csr_pem($csr), $no_text);
    if ($out === '') { return false; }
    $output = $out;

    return true;
}

function openssl_csr_export_to_file(OpenSSLCertificateSigningRequest|string $csr, string $output_filename, bool $no_text = true): bool
{
    $out = \__mc_ossl_csr_export(\__mc_ossl_csr_pem($csr), $no_text);
    if ($out === '') { return false; }

    return \file_put_contents($output_filename, $out) !== false;
}

/** @return OpenSSLAsymmetricKey|false */
function openssl_csr_get_public_key(OpenSSLCertificateSigningRequest|string $csr, bool $short_names = true)
{
    $spki = \__mc_ossl_csr_pubkey(\__mc_ossl_csr_pem($csr));
    if ($spki === '') { return false; }

    return new OpenSSLAsymmetricKey(\__mc_pem_der($spki, 'PUBLIC KEY'));
}

/**
 * `openssl_csr_sign($csr, $ca, $key, $days, $options, $serial)` — a certificate for the
 * request, issued by $ca (self-signed when null) under $private_key.
 *
 * @param array<string,mixed>|null $options
 * @return OpenSSLCertificate|false
 */
function openssl_csr_sign(OpenSSLCertificateSigningRequest|string $csr, OpenSSLCertificate|string|null $ca_certificate, mixed $private_key, int $days, ?array $options = null, int $serial = 0)
{
    if ($options !== null) { \__mc_ossl_no_config('openssl_csr_sign', $options); }
    $priv = \__mc_ossl_priv_of($private_key, null);
    if ($priv === '') {
        throw new \ValueError('openssl_csr_sign(): Argument #3 ($private_key) must be a valid private key');
    }
    $ca = '';
    if ($ca_certificate !== null) {
        $ca = $ca_certificate instanceof OpenSSLCertificate ? $ca_certificate->pem : \__mc_ossl_arg_text($ca_certificate);
    }
    $digest = 'sha256';
    if ($options !== null && isset($options['digest_alg'])) { $digest = (string)$options['digest_alg']; }
    $pem = \__mc_ossl_csr_sign(\__mc_ossl_csr_pem($csr), $ca, $priv, $days, $digest, $serial);
    if ($pem === '') { return false; }

    return new OpenSSLCertificate($pem);
}

// ── seal / open ───────────────────────────────────────────────────────────

/**
 * `openssl_seal` — a random session key encrypts $data under $cipher_algo and is itself
 * RSA-encrypted (PKCS#1 v1.5) for every public key, exactly what EVP_Seal does.
 *
 * @param string[] $encrypted_keys
 * @param array<int,mixed> $public_key
 */
function openssl_seal(string $data, #[\Manticore\Attr\RefOut] ?string &$sealed_data, #[\Manticore\Attr\RefOut] ?array &$encrypted_keys, array $public_key, string $cipher_algo, #[\Manticore\Attr\RefOut] ?string &$iv = null): int|false
{
    if (\count($public_key) === 0) {
        throw new \ValueError('openssl_seal(): Argument #4 ($public_key) cannot be empty');
    }
    $keyLen = \openssl_cipher_key_length($cipher_algo);
    $ivLen = \openssl_cipher_iv_length($cipher_algo);
    $key = \random_bytes((int)$keyLen);
    $ivBytes = $ivLen > 0 ? \random_bytes((int)$ivLen) : '';
    $sealed = \openssl_encrypt($data, $cipher_algo, $key, 1, $ivBytes);
    if ($sealed === false) { return false; }
    /** @var string[] $keys */
    $keys = [];
    foreach ($public_key as $pk) {
        $pem = \__mc_ossl_pub_of($pk);
        if ($pem === '') {
            throw new \ValueError('openssl_seal(): Argument #4 ($public_key) must contain only valid public keys');
        }
        $ek = \__mc_ossl_rsa(0, $key, $pem, 1);
        if ($ek === false) { return false; }
        $keys[] = (string)$ek;
    }
    $sealed_data = (string)$sealed;
    $encrypted_keys = $keys;
    $iv = $ivBytes;

    return \strlen((string)$sealed);
}

function openssl_open(string $data, #[\Manticore\Attr\RefOut] ?string &$output, string $encrypted_key, mixed $private_key, string $cipher_algo, ?string $iv = null): bool
{
    $priv = \__mc_ossl_priv_of($private_key, null);
    if ($priv === '') {
        throw new \ValueError('openssl_open(): Argument #4 ($private_key) must be a valid private key');
    }
    $ivLen = (int)\openssl_cipher_iv_length($cipher_algo);
    if ($ivLen > 0 && ($iv === null || \strlen($iv) === 0)) {
        throw new \ValueError('openssl_open(): Argument #6 ($iv) cannot be null for the chosen cipher algorithm');
    }
    $key = \__mc_ossl_rsa(1, $encrypted_key, $priv, 1);
    if ($key === false) { return false; }
    $plain = \openssl_decrypt($data, $cipher_algo, (string)$key, 1, $ivLen > 0 ? (string)$iv : '');
    if ($plain === false) { return false; }
    $output = (string)$plain;

    return true;
}

// ── SPKAC ─────────────────────────────────────────────────────────────────

/** `SPKAC=<base64>` for the key and challenge. */
function openssl_spki_new(mixed $private_key, string $challenge, int $digest_algo = 2): string|false
{
    $priv = \__mc_ossl_priv_of($private_key, null);
    if ($priv === '') {
        throw new \ValueError('openssl_spki_new(): Argument #1 ($private_key) must be a valid private key');
    }
    $r = \__mc_ossl_spki_new($priv, $challenge, \__mc_ossl_algo_name('openssl_spki_new', $digest_algo));

    return $r === '' ? false : $r;
}

function openssl_spki_verify(string $spki): bool
{
    return \__mc_ossl_spki_verify($spki);
}

function openssl_spki_export(string $spki): string|false
{
    $r = \__mc_ossl_spki_export($spki);

    return $r === '' ? false : $r;
}

function openssl_spki_export_challenge(string $spki): string|false
{
    $r = \__mc_ossl_spki_challenge($spki);

    return $r === false ? false : (string)$r;
}

// ── PKCS#12 ───────────────────────────────────────────────────────────────

/** The PEM of every certificate named by $extracerts (a PEM string, array of them, or a file). */
function __mc_ossl_cert_bundle(mixed $certs): string
{
    if ($certs instanceof OpenSSLCertificate) { return $certs->pem; }
    if (\is_string($certs)) { return \__mc_ossl_arg_text($certs); }
    if (\is_array($certs)) {
        $out = '';
        foreach ($certs as $c) { $out = $out . \__mc_ossl_cert_bundle($c); }

        return $out;
    }

    return '';
}

/** @param array<string,mixed>|null $options */
function __mc_ossl_p12_blob(mixed $certificate, mixed $private_key, string $passphrase, ?array $options): string
{
    $cert = \__mc_ossl_cert_bundle($certificate);
    $priv = \__mc_ossl_priv_of($private_key, null);
    if ($cert === '' || $priv === '') {
        throw new \ValueError('openssl_pkcs12_export(): the certificate and private key must be valid');
    }
    $friendly = '';
    $extra = '';
    if ($options !== null) {
        if (isset($options['friendly_name'])) { $friendly = (string)$options['friendly_name']; }
        if (isset($options['extracerts'])) { $extra = \__mc_ossl_cert_bundle($options['extracerts']); }
    }

    return \__mc_ossl_p12_export($cert, $priv, $passphrase, $friendly, $extra);
}

/** @param array<string,mixed>|null $options */
function openssl_pkcs12_export(mixed $certificate, #[\Manticore\Attr\RefOut] ?string &$output, mixed $private_key, string $passphrase, array $options = []): bool
{
    $der = \__mc_ossl_p12_blob($certificate, $private_key, $passphrase, $options);
    if ($der === '') { return false; }
    $output = $der;

    return true;
}

/** @param array<string,mixed> $options */
function openssl_pkcs12_export_to_file(mixed $certificate, string $output_filename, mixed $private_key, string $passphrase, array $options = []): bool
{
    $der = \__mc_ossl_p12_blob($certificate, $private_key, $passphrase, $options);
    if ($der === '') { return false; }

    return \file_put_contents($output_filename, $der) !== false;
}

/** @param array<string,mixed> $certificates */
function openssl_pkcs12_read(string $pkcs12, #[\Manticore\Attr\RefOut] ?array &$certificates, string $passphrase): bool
{
    $r = \__mc_ossl_p12_read($pkcs12, $passphrase);
    if (\count($r) === 0) { return false; }
    $certificates = $r;

    return true;
}

// ── PKCS#7 ────────────────────────────────────────────────────────────────

/** php writes the caller's headers ("Key: value" lines) ahead of the S-MIME body. @param array<int|string,string>|null $headers */
function __mc_ossl_p7_headers(?array $headers): string
{
    $out = '';
    if ($headers === null) { return $out; }
    foreach ($headers as $k => $v) {
        $out = $out . (\is_int($k) ? (string)$v : $k . ': ' . $v) . "\n";
    }

    return $out;
}

/** @param array<int|string,string>|null $headers */
function openssl_pkcs7_sign(string $input_filename, string $output_filename, OpenSSLCertificate|string $certificate, mixed $private_key, ?array $headers, int $flags = 64, ?string $untrusted_certificates_filename = null): bool
{
    $data = \file_get_contents($input_filename);
    if ($data === false) { return false; }
    $priv = \__mc_ossl_priv_of($private_key, null);
    if ($priv === '') {
        throw new \ValueError('openssl_pkcs7_sign(): Argument #4 ($private_key) must be a valid private key');
    }
    $extra = '';
    if ($untrusted_certificates_filename !== null) {
        $c = \file_get_contents($untrusted_certificates_filename);
        if ($c === false) { return false; }
        $extra = $c;
    }
    $smime = \__mc_ossl_p7_sign($data, \__mc_ossl_cert_bundle($certificate), $priv, $extra, $flags);
    if ($smime === '') { return false; }

    return \file_put_contents($output_filename, \__mc_ossl_p7_headers($headers) . $smime) !== false;
}

/**
 * @param array<int,string> $ca_info
 * @return bool|int true when the signature verifies, false when not, -1 on error
 */
function openssl_pkcs7_verify(string $input_filename, int $flags, ?string $signers_certificates_filename = null, array $ca_info = [], ?string $untrusted_certificates_filename = null, ?string $content = null, ?string $output_filename = null)
{
    $smime = \file_get_contents($input_filename);
    if ($smime === false) { return -1; }
    $untrusted = '';
    if ($untrusted_certificates_filename !== null) {
        $c = \file_get_contents($untrusted_certificates_filename);
        if ($c === false) { return -1; }
        $untrusted = $c;
    }
    $r = \__mc_ossl_p7_verify($smime, $flags, $ca_info, $untrusted, '');
    $status = (int)$r[0];
    if ($status === 1) {
        if ($signers_certificates_filename !== null) {
            \file_put_contents($signers_certificates_filename, (string)$r[2]);
        }
        // $content is where the verified data (signature stripped) goes. php also names an
        // $output_filename and leaves it empty for a successful verification, so it is not written.
        if ($content !== null) {
            \file_put_contents($content, (string)$r[1]);
        }
    }
    return $status < 0 ? -1 : $status === 1;
}

/**
 * @param OpenSSLCertificate|string|array<int,mixed> $certificate
 * @param array<int|string,string>|null $headers
 */
function openssl_pkcs7_encrypt(string $input_filename, string $output_filename, mixed $certificate, ?array $headers, int $flags = 0, int $cipher_algo = 5): bool
{
    $data = \file_get_contents($input_filename);
    if ($data === false) { return false; }
    /** @var array<int,string> $names */
    $names = [0 => 'rc2-40-cbc', 1 => 'rc2-cbc', 2 => 'rc2-64-cbc', 3 => 'des-cbc', 4 => 'des-ede3-cbc',
              5 => 'aes-128-cbc', 6 => 'aes-192-cbc', 7 => 'aes-256-cbc'];
    if (!isset($names[$cipher_algo])) {
        throw new \ValueError('openssl_pkcs7_encrypt(): Argument #6 ($cipher_algo) must be a valid cipher algorithm');
    }
    /** @var string[] $pems */
    $pems = [];
    if (\is_array($certificate)) {
        foreach ($certificate as $c) { $pems[] = \__mc_ossl_cert_bundle($c); }
    } else {
        $pems[] = \__mc_ossl_cert_bundle($certificate);
    }
    $smime = \__mc_ossl_p7_encrypt($data, $pems, $names[$cipher_algo], $flags);
    if ($smime === '') { return false; }

    return \file_put_contents($output_filename, \__mc_ossl_p7_headers($headers) . $smime) !== false;
}

function openssl_pkcs7_decrypt(string $input_filename, string $output_filename, OpenSSLCertificate|string $certificate, mixed $private_key = null): bool
{
    $smime = \file_get_contents($input_filename);
    if ($smime === false) { return false; }
    $pem = \__mc_ossl_cert_bundle($certificate);
    $priv = \__mc_ossl_priv_of($private_key === null ? $certificate : $private_key, null);
    if ($priv === '') { return false; }
    $plain = \__mc_ossl_p7_decrypt($smime, $pem, $priv);
    if ($plain === false) { return false; }

    return \file_put_contents($output_filename, (string)$plain) !== false;
}

/**
 * `openssl_pkcs7_read($data, &$certificates)` — the certificates of a PEM PKCS#7
 * SignedData, read straight from the DER (SignedData's `[0] certificates` field).
 *
 * @param string[] $certificates
 */
function openssl_pkcs7_read(string $data, #[\Manticore\Attr\RefOut] ?array &$certificates): bool
{
    $der = \__mc_pem_der($data, 'PKCS7');
    if ($der === '') { $der = \__mc_pem_der($data, 'CERTIFICATE'); }
    if ($der === '') { return false; }
    $ci = \__mc_der_tlv($der, 0);                       // ContentInfo
    if (\count($ci) === 0) { return false; }
    $oid = \__mc_der_tlv($der, $ci[1]);
    if (\count($oid) === 0) { return false; }
    $wrap = \__mc_der_tlv($der, $oid[3]);              // [0] EXPLICIT
    if (\count($wrap) === 0) { return false; }
    $sd = \__mc_der_tlv($der, $wrap[1]);               // SignedData
    if (\count($sd) === 0) { return false; }
    /** @var string[] $out */
    $out = [];
    $p = $sd[1];
    $end = $sd[1] + $sd[2];
    while ($p < $end) {
        $t = \__mc_der_tlv($der, $p);
        if (\count($t) === 0) { return false; }
        if ($t[0] === 0xA0) {                           // [0] IMPLICIT certificates
            $q = $t[1];
            $qEnd = $t[1] + $t[2];
            while ($q < $qEnd) {
                $c = \__mc_der_tlv($der, $q);
                if (\count($c) === 0) { return false; }
                $out[] = \__mc_der_pem(\substr($der, $q, $c[3] - $q), 'CERTIFICATE');
                $q = $c[3];
            }
        }
        $p = $t[3];
    }
    $certificates = $out;

    return true;
}
