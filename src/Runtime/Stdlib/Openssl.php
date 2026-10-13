<?php

// ext/openssl over the host libcrypto (bindings: Runtime/Crypto.php).
//
// Keys and certificates cross this boundary as PEM TEXT, never as a pointer:
// each operation parses the PEM into an EVP_PKEY / X509, uses it and frees it,
// so no handle outlives a call and the PHP objects (prelude/openssl_x509.php)
// are plain strings. Where php warns and carries on, this throws.


/** Zeroed C buffer, as a Ptr. ponytail: calloc failure is not handled, as in Hash.php's twin it would be an OOM abort anyway. */
function __mc_ossl_buf(int $n): \Ffi\Ptr
{
    return \Runtime\Libc\calloc($n, 1);
}

function __mc_ossl_null(): \Ffi\Ptr
{
    return \int_to_ptr(0);
}

/** The pending libcrypto error, oldest first, or false when the queue is empty. */
function openssl_error_string(): string|false
{
    $e = \Runtime\Crypto\errGetError();
    if ($e === 0) { return false; }
    $b = \__mc_ossl_buf(256);
    \Runtime\Crypto\errErrorStringN($e, $b, 256);
    $s = \cstr_to_str($b);
    \Runtime\Libc\free($b);

    return $s;
}

/** Drop the error queue before an operation whose failure we report as false. */
function __mc_ossl_clear_errors(): void
{
    while (\Runtime\Crypto\errGetError() !== 0) {
    }
}

/** Everything left in a memory BIO, then the BIO freed. */
function __mc_ossl_bio_take(int $bio): string
{
    $out = '';
    $b = \__mc_ossl_buf(4096);
    while (true) {
        $n = \Runtime\Crypto\bioRead($bio, $b, 4096);
        if ($n <= 0) { break; }
        $out = $out . \str_from_buffer($b, $n);
    }
    \Runtime\Libc\free($b);
    \Runtime\Crypto\bioFree($bio);

    return $out;
}

/** A NUL-terminated C string at $p, freed with OPENSSL_free; '' for NULL. */
function __mc_ossl_take_cstr(\Ffi\Ptr $p): string
{
    if (\ptr_to_int($p) === 0) { return ''; }
    $s = \cstr_to_str($p);
    \Runtime\Crypto\cryptoFree($p, 'openssl', 0);

    return $s;
}

// ── ciphers and digests ───────────────────────────────────────────────────

/** @return string[] */
function __mc_ossl_cipher_candidates(): array
{
    $out = [];
    $bits = ['128', '192', '256'];
    $modes = ['cbc', 'ecb', 'cfb', 'cfb1', 'cfb8', 'ofb', 'ctr', 'gcm', 'ccm'];
    foreach ($bits as $b) {
        foreach ($modes as $m) { $out[] = 'aes-' . $b . '-' . $m; }
        $out[] = 'camellia-' . $b . '-cbc';
        $out[] = 'camellia-' . $b . '-ecb';
        $out[] = 'aria-' . $b . '-cbc';
        $out[] = 'aria-' . $b . '-gcm';
    }
    foreach (['des-ede3-cbc', 'des-ede3', 'des-ede-cbc', 'des-cbc', 'des-ecb', 'bf-cbc', 'cast5-cbc',
              'rc2-cbc', 'rc4', 'chacha20', 'chacha20-poly1305', 'aes-128-xts', 'aes-256-xts',
              'id-aes128-wrap', 'id-aes256-wrap', 'sm4-cbc'] as $n) {
        $out[] = $n;
    }

    return $out;
}

/** @return string[] */
function openssl_get_cipher_methods(bool $aliases = false): array
{
    $out = [];
    foreach (\__mc_ossl_cipher_candidates() as $n) {
        if (\Runtime\Crypto\getCipherByName($n) !== 0) {
            $out[] = $n;
            if ($aliases) { $out[] = \strtoupper($n); }
        }
    }
    \sort($out);

    return $out;
}

/** @return string[] */
function openssl_get_md_methods(bool $aliases = false): array
{
    $out = [];
    foreach (['md5', 'sha1', 'sha224', 'sha256', 'sha384', 'sha512', 'sha512-224', 'sha512-256',
              'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512', 'ripemd160', 'blake2b512', 'blake2s256',
              'sm3', 'shake128', 'shake256', 'md4', 'whirlpool', 'mdc2'] as $n) {
        if (\Runtime\Crypto\getDigestByName($n) !== 0) {
            $out[] = $n;
            if ($aliases) { $out[] = \strtoupper($n); }
        }
    }
    \sort($out);

    return $out;
}

function openssl_cipher_iv_length(string $cipher_algo): int|false
{
    $c = \Runtime\Crypto\getCipherByName($cipher_algo);
    if ($c === 0) {
        throw new \ValueError('openssl_cipher_iv_length(): Argument #1 ($cipher_algo) must be a valid cipher algorithm');
    }

    return \Runtime\Crypto\cipherIvLen($c);
}

function openssl_cipher_key_length(string $cipher_algo): int|false
{
    $c = \Runtime\Crypto\getCipherByName($cipher_algo);
    if ($c === 0) {
        throw new \ValueError('openssl_cipher_key_length(): Argument #1 ($cipher_algo) must be a valid cipher algorithm');
    }

    return \Runtime\Crypto\cipherKeyLen($c);
}

/** EVP_MD* for a php digest argument; ValueError when unknown. */
function __mc_ossl_md(string $fn, int $argNo, string $argName, string $name): int
{
    $md = \Runtime\Crypto\getDigestByName($name);
    if ($md === 0) {
        throw new \ValueError($fn . '(): Argument #' . $argNo . ' ($' . $argName . ') must be a valid digest algorithm');
    }

    return $md;
}

function openssl_digest(string $data, string $digest_algo, bool $binary = false): string|false
{
    $md = \__mc_ossl_md('openssl_digest', 2, 'digest_algo', $digest_algo);
    $buf = \__mc_ossl_buf(64);
    $sz = \__mc_ossl_buf(8);
    $ok = \Runtime\Crypto\evpDigest($data, \strlen($data), $buf, $sz, $md, \__mc_ossl_null());
    $out = $ok === 1 ? \str_from_buffer($buf, \peek_u32($sz, 0)) : false;
    \Runtime\Libc\free($buf);
    \Runtime\Libc\free($sz);
    if ($out === false) { return false; }

    return $binary ? $out : \bin2hex($out);
}

function openssl_random_pseudo_bytes(int $length, #[\Manticore\Attr\RefOut] ?bool &$strong_result = null): string
{
    if ($length < 1) {
        throw new \ValueError('openssl_random_pseudo_bytes(): Argument #1 ($length) must be greater than 0');
    }
    $strong_result = true;

    return \random_bytes($length);
}

function openssl_pbkdf2(string $password, string $salt, int $key_length, int $iterations, string $digest_algo = 'sha1'): string|false
{
    if ($key_length < 1) {
        throw new \ValueError('openssl_pbkdf2(): Argument #3 ($key_length) must be greater than 0');
    }
    if ($iterations < 1) {
        throw new \ValueError('openssl_pbkdf2(): Argument #4 ($iterations) must be greater than 0');
    }
    \__mc_ossl_md('openssl_pbkdf2', 5, 'digest_algo', $digest_algo);
    if (!\in_array(\strtolower($digest_algo), \hash_algos(), true)) {
        throw new \ValueError('openssl_pbkdf2(): Argument #5 ($digest_algo) must be a valid digest algorithm');
    }

    return \hash_pbkdf2(\strtolower($digest_algo), $password, $salt, $iterations, $key_length, true);
}

/**
 * Shared body of openssl_encrypt / openssl_decrypt. $tag is the AEAD tag in (decrypt)
 * or, when encrypting, empty on entry and the tag on return in $tagOut.
 *
 * @return string|false
 */
function __mc_ossl_crypt(bool $enc, string $fn, string $data, string $method, string $key, int $options,
                         string $iv, string $tag, string $aad, int $tagLength, string &$tagOut)
{
    $cipher = \Runtime\Crypto\getCipherByName($method);
    if ($cipher === 0) {
        throw new \ValueError($fn . '(): Argument #2 ($cipher_algo) must be a valid cipher algorithm');
    }
    $flags = \Runtime\Crypto\cipherFlags($cipher);
    $mode = $flags & 0xF0007;
    $aead = $mode === 6 || ($flags & 0x200000) !== 0;
    if ($mode === 7 || $mode === 0x10003) {
        throw new \ValueError($fn . '(): CCM and OCB ciphers are not supported');
    }
    $keyLen = \Runtime\Crypto\cipherKeyLen($cipher);
    $ivLen = \Runtime\Crypto\cipherIvLen($cipher);
    $ivGiven = \strlen($iv);

    if ($aead) {
        if ($ivGiven === 0) {
            throw new \ValueError($fn . '(): Argument #5 ($iv) must not be empty for an AEAD cipher');
        }
    } elseif ($ivLen > 0) {
        if ($ivGiven === 0) {
            throw new \ValueError($fn . '(): Using an empty Initialization Vector (iv) is potentially insecure and not recommended');
        }
        if ($ivGiven !== $ivLen) {
            throw new \ValueError($fn . '(): Argument #5 ($iv) must be exactly ' . $ivLen . ' bytes long for this cipher, ' . $ivGiven . ' given');
        }
    }
    if (\strlen($key) !== $keyLen) {
        if (($options & 4) !== 0) {
            return false;
        }
        $key = \strlen($key) < $keyLen ? \str_pad($key, $keyLen, "\0") : \substr($key, 0, $keyLen);
    }

    if (!$enc) {
        if (($options & 1) === 0) {
            $d = \base64_decode($data, false);
            if ($d === false) { return false; }
            $data = $d;
        }
    }

    \__mc_ossl_clear_errors();
    $ctx = \Runtime\Crypto\cipherCtxNew();
    $nul = \__mc_ossl_null();
    $ok = \Runtime\Crypto\cipherInit0($ctx, \int_to_ptr($cipher), $nul, $nul, $nul, $enc ? 1 : 0) === 1;
    if ($ok && $aead) {
        $ok = \Runtime\Crypto\cipherCtrlPtr($ctx, 9, $ivGiven, $nul) === 1;
    }
    if ($ok && ($options & 2) !== 0) {
        \Runtime\Crypto\cipherSetPadding($ctx, 0);
    }
    if ($ok) {
        $ok = \Runtime\Crypto\cipherInitKey($ctx, $nul, $nul, $key, $iv, $enc ? 1 : 0) === 1;
    }
    if ($ok && $aead && !$enc) {
        if ($tag === '') {
            \Runtime\Crypto\cipherCtxFree($ctx);
            throw new \ValueError($fn . '(): A tag must be given for AEAD decryption');
        }
        $ok = \Runtime\Crypto\cipherCtrlStr($ctx, 0x11, \strlen($tag), $tag) === 1;
    }
    $outl = \__mc_ossl_buf(8);
    $cap = \strlen($data) + \Runtime\Crypto\cipherBlockSize($cipher) + 16;
    $out = \__mc_ossl_buf($cap);
    $res = false;
    if ($ok && $aead && $aad !== '') {
        $ok = \Runtime\Crypto\cipherUpdate($ctx, $nul, $outl, $aad, \strlen($aad)) === 1;
    }
    if ($ok) {
        $ok = \Runtime\Crypto\cipherUpdate($ctx, $out, $outl, $data, \strlen($data)) === 1;
    }
    if ($ok) {
        $n = \peek_u32($outl, 0);
        $head = \str_from_buffer($out, $n);
        $ok = \Runtime\Crypto\cipherFinal($ctx, $out, $outl) === 1;
        if ($ok) {
            $res = $head . \str_from_buffer($out, \peek_u32($outl, 0));
            if ($enc && $aead) {
                $tb = \__mc_ossl_buf($tagLength);
                if (\Runtime\Crypto\cipherCtrlPtr($ctx, 0x10, $tagLength, $tb) === 1) {
                    $tagOut = \str_from_buffer($tb, $tagLength);
                }
                \Runtime\Libc\free($tb);
            }
        }
    }
    \Runtime\Libc\free($outl);
    \Runtime\Libc\free($out);
    \Runtime\Crypto\cipherCtxFree($ctx);
    if (!$ok || $res === false) { return false; }
    if ($enc && ($options & 1) === 0) { return \base64_encode($res); }

    return $res;
}

function openssl_encrypt(string $data, string $cipher_algo, string $passphrase, int $options = 0, string $iv = '',
                         #[\Manticore\Attr\RefOut] ?string &$tag = null, string $aad = '', int $tag_length = 16): string|false
{
    if ($tag_length < 1 || $tag_length > 16) {
        throw new \ValueError('openssl_encrypt(): Argument #8 ($tag_length) must be between 1 and 16');
    }
    $t = '';
    $r = \__mc_ossl_crypt(true, 'openssl_encrypt', $data, $cipher_algo, $passphrase, $options, $iv, '', $aad, $tag_length, $t);

    if ($t !== '') { $tag = $t; }

    return $r;
}

function openssl_decrypt(string $data, string $cipher_algo, string $passphrase, int $options = 0, string $iv = '',
                         ?string $tag = null, string $aad = ''): string|false
{
    $unused = '';

    return \__mc_ossl_crypt(false, 'openssl_decrypt', $data, $cipher_algo, $passphrase, $options, $iv, $tag ?? '', $aad, 16, $unused);
}

// ── keys ──────────────────────────────────────────────────────────────────

/** EVP_PKEY* from a PEM private key (0 on failure). The caller frees it. */
function __mc_ossl_load_priv(string $pem, string $pass): int
{
    $bio = \Runtime\Crypto\bioNewMemBuf($pem, \strlen($pem));
    $k = \Runtime\Crypto\pemReadPrivateKey($bio, \__mc_ossl_null(), \__mc_ossl_null(), $pass);
    \Runtime\Crypto\bioFree($bio);

    return $k;
}

/** EVP_PKEY* from a PEM SPKI / certificate / private key (0 on failure). */
function __mc_ossl_load_pub(string $pem): int
{
    $nul = \__mc_ossl_null();
    $bio = \Runtime\Crypto\bioNewMemBuf($pem, \strlen($pem));
    $k = \Runtime\Crypto\pemReadPubkey($bio, $nul, $nul, $nul);
    \Runtime\Crypto\bioFree($bio);
    if ($k !== 0) { return $k; }
    $bio = \Runtime\Crypto\bioNewMemBuf($pem, \strlen($pem));
    $x = \Runtime\Crypto\pemReadX509($bio, $nul, $nul, $nul);
    \Runtime\Crypto\bioFree($bio);
    if ($x !== 0) {
        // X509_get0_pubkey is borrowed; round-trip through PEM so the caller owns a key.
        $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
        \Runtime\Crypto\pemWritePubkey($mem, \Runtime\Crypto\x509Pubkey($x));
        \Runtime\Crypto\x509Free($x);
        $spki = \__mc_ossl_bio_take($mem);

        return \__mc_ossl_load_pub($spki);
    }

    return \__mc_ossl_load_priv($pem, '');
}

/** Unencrypted PKCS#8 PEM of the private key in $pem (decrypted with $pass), or ''. */
function __mc_ossl_priv_pem(string $pem, string $pass): string
{
    \__mc_ossl_clear_errors();
    $k = \__mc_ossl_load_priv($pem, $pass);
    if ($k === 0) { return ''; }
    $nul = \__mc_ossl_null();
    $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
    \Runtime\Crypto\pemWritePkcs8Plain($mem, $k, $nul, $nul, 0, $nul, $nul);
    \Runtime\Crypto\pkeyFree($k);

    return \__mc_ossl_bio_take($mem);
}

/** SubjectPublicKeyInfo PEM for a key / certificate / private-key PEM, or ''. */
function __mc_ossl_spki_pem(string $pem): string
{
    \__mc_ossl_clear_errors();
    $k = \__mc_ossl_load_pub($pem);
    if ($k === 0) { return ''; }
    $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
    \Runtime\Crypto\pemWritePubkey($mem, $k);
    \Runtime\Crypto\pkeyFree($k);

    return \__mc_ossl_bio_take($mem);
}

/** Re-encrypt a private key PEM under $pass (DES-EDE3-CBC, php's default); '' on failure. */
function __mc_ossl_priv_export(string $pem, string $pass): string
{
    if ($pass === '') { return $pem; }
    $k = \__mc_ossl_load_priv($pem, '');
    if ($k === 0) { return ''; }
    $nul = \__mc_ossl_null();
    $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
    $r = \Runtime\Crypto\pemWritePkcs8Enc($mem, $k, \int_to_ptr(\Runtime\Crypto\getCipherByName('des-ede3-cbc')), $pass, \strlen($pass), $nul, $nul);
    \Runtime\Crypto\pkeyFree($k);
    $out = \__mc_ossl_bio_take($mem);

    if ($r !== 1) { return ''; }

    return $out;
}

/** A fresh private key as unencrypted PKCS#8 PEM: RSA ($curve === '') or EC. '' on failure. */
function __mc_ossl_pkey_new(int $type, int $bits, string $curve): string
{
    \__mc_ossl_clear_errors();
    if ($type !== 0 && $type !== 3) {
        throw new \ValueError('openssl_pkey_new(): only OPENSSL_KEYTYPE_RSA and OPENSSL_KEYTYPE_EC are supported');
    }
    $nul = \__mc_ossl_null();
    // EVP_PKEY_RSA = 6, EVP_PKEY_EC = 408.
    $ctx = \Runtime\Crypto\pkeyCtxNewId($type === 0 ? 6 : 408, $nul);
    $k = 0;
    if ($ctx !== 0 && \Runtime\Crypto\pkeyKeygenInit($ctx) === 1) {
        $ok = true;
        if ($type === 0) {
            $ok = \Runtime\Crypto\pkeyCtxSetRsaKeygenBits($ctx, $bits) === 1;
        } else {
            $nid = \Runtime\Crypto\objTxt2nid($curve === '' ? 'prime256v1' : $curve);
            $ok = $nid !== 0 && \Runtime\Crypto\pkeyCtxSetEcCurveNid($ctx, $nid) === 1;
        }
        if ($ok) {
            $cell = \__mc_ossl_buf(8);
            if (\Runtime\Crypto\pkeyKeygen($ctx, $cell) === 1) { $k = \peek_i64($cell, 0); }
            \Runtime\Libc\free($cell);
        }
    }
    if ($ctx !== 0) { \Runtime\Crypto\pkeyCtxFree($ctx); }
    if ($k === 0) { return ''; }
    $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
    \Runtime\Crypto\pemWritePkcs8Plain($mem, $k, $nul, $nul, 0, $nul, $nul);
    \Runtime\Crypto\pkeyFree($k);

    return \__mc_ossl_bio_take($mem);
}

/** Digest algorithm for a php signature argument: OPENSSL_ALGO_* int or a name. */
function __mc_ossl_algo_name(string $fn, int $algorithm): string
{
    $map = [1 => 'sha1', 2 => 'md5', 3 => 'md4', 6 => 'sha224', 7 => 'sha256', 8 => 'sha384', 9 => 'sha512', 10 => 'ripemd160'];
    if (!isset($map[$algorithm])) {
        throw new \ValueError($fn . '(): Argument #4 ($algorithm) must be a valid signature algorithm');
    }

    return $map[$algorithm];
}

/** Signature of $data under the private key $privPem, or false. */
function __mc_ossl_sign(string $data, string $privPem, string $algo)
{
    $md = \__mc_ossl_md('openssl_sign', 4, 'algorithm', $algo);
    \__mc_ossl_clear_errors();
    $k = \__mc_ossl_load_priv($privPem, '');
    if ($k === 0) { return false; }
    $nul = \__mc_ossl_null();
    $ctx = \Runtime\Crypto\mdCtxNew();
    $res = false;
    $lenCell = \__mc_ossl_buf(8);
    if (\Runtime\Crypto\digestSignInit($ctx, $nul, $md, $nul, $k) === 1
        && \Runtime\Crypto\digestSign($ctx, $nul, $lenCell, $data, \strlen($data)) === 1) {
        $cap = \peek_i64($lenCell, 0);
        $sig = \__mc_ossl_buf($cap);
        if (\Runtime\Crypto\digestSign($ctx, $sig, $lenCell, $data, \strlen($data)) === 1) {
            $res = \str_from_buffer($sig, \peek_i64($lenCell, 0));
        }
        \Runtime\Libc\free($sig);
    }
    \Runtime\Libc\free($lenCell);
    \Runtime\Crypto\mdCtxFree($ctx);
    \Runtime\Crypto\pkeyFree($k);

    return $res;
}

/** 1 valid, 0 invalid, -1 error — php's openssl_verify answer. */
function __mc_ossl_verify(string $data, string $signature, string $pubPem, string $algo): int
{
    $md = \__mc_ossl_md('openssl_verify', 4, 'algorithm', $algo);
    \__mc_ossl_clear_errors();
    $k = \__mc_ossl_load_pub($pubPem);
    if ($k === 0) { return -1; }
    $nul = \__mc_ossl_null();
    $ctx = \Runtime\Crypto\mdCtxNew();
    $r = -1;
    if (\Runtime\Crypto\digestVerifyInit($ctx, $nul, $md, $nul, $k) === 1) {
        $v = \Runtime\Crypto\digestVerify($ctx, $signature, \strlen($signature), $data, \strlen($data));
        $r = $v === 1 ? 1 : ($v === 0 ? 0 : -1);
    }
    \Runtime\Crypto\mdCtxFree($ctx);
    \Runtime\Crypto\pkeyFree($k);

    return $r;
}

/**
 * Raw RSA: $op 0 = public encrypt, 1 = private decrypt, 2 = private encrypt (sign),
 * 3 = public decrypt (verify-recover). $keyPem is a private PEM for 1/2, public for 0/3.
 *
 * @return string|false
 */
function __mc_ossl_rsa(int $op, string $data, string $keyPem, int $padding)
{
    \__mc_ossl_clear_errors();
    $k = ($op === 1 || $op === 2) ? \__mc_ossl_load_priv($keyPem, '') : \__mc_ossl_load_pub($keyPem);
    if ($k === 0) { return false; }
    $nul = \__mc_ossl_null();
    $ctx = \Runtime\Crypto\pkeyCtxNew($k, $nul);
    $ok = $ctx !== 0;
    if ($ok) {
        if ($op === 0) { $ok = \Runtime\Crypto\pkeyEncryptInit($ctx) === 1; }
        elseif ($op === 1) { $ok = \Runtime\Crypto\pkeyDecryptInit($ctx) === 1; }
        elseif ($op === 2) { $ok = \Runtime\Crypto\pkeySignInit($ctx) === 1; }
        else { $ok = \Runtime\Crypto\pkeyVerifyRecoverInit($ctx) === 1; }
    }
    if ($ok) {
        $ok = \Runtime\Crypto\pkeyCtxSetRsaPadding($ctx, $padding) === 1;
    }
    $res = false;
    $lenCell = \__mc_ossl_buf(8);
    $n = \strlen($data);
    if ($ok) {
        if ($op === 0) { $ok = \Runtime\Crypto\pkeyEncrypt($ctx, $nul, $lenCell, $data, $n) === 1; }
        elseif ($op === 1) { $ok = \Runtime\Crypto\pkeyDecrypt($ctx, $nul, $lenCell, $data, $n) === 1; }
        elseif ($op === 2) { $ok = \Runtime\Crypto\pkeySign($ctx, $nul, $lenCell, $data, $n) === 1; }
        else { $ok = \Runtime\Crypto\pkeyVerifyRecover($ctx, $nul, $lenCell, $data, $n) === 1; }
    }
    if ($ok) {
        $out = \__mc_ossl_buf(\peek_i64($lenCell, 0) + 1);
        if ($op === 0) { $ok = \Runtime\Crypto\pkeyEncrypt($ctx, $out, $lenCell, $data, $n) === 1; }
        elseif ($op === 1) { $ok = \Runtime\Crypto\pkeyDecrypt($ctx, $out, $lenCell, $data, $n) === 1; }
        elseif ($op === 2) { $ok = \Runtime\Crypto\pkeySign($ctx, $out, $lenCell, $data, $n) === 1; }
        else { $ok = \Runtime\Crypto\pkeyVerifyRecover($ctx, $out, $lenCell, $data, $n) === 1; }
        if ($ok) { $res = \str_from_buffer($out, \peek_i64($lenCell, 0)); }
        \Runtime\Libc\free($out);
    }
    \Runtime\Libc\free($lenCell);
    if ($ctx !== 0) { \Runtime\Crypto\pkeyCtxFree($ctx); }
    \Runtime\Crypto\pkeyFree($k);

    return $res;
}

// ── X.509 ─────────────────────────────────────────────────────────────────

/** X509* from a PEM certificate (0 on failure). The caller frees it. */
function __mc_ossl_load_x509(string $pem): int
{
    $nul = \__mc_ossl_null();
    $bio = \Runtime\Crypto\bioNewMemBuf($pem, \strlen($pem));
    $x = \Runtime\Crypto\pemReadX509($bio, $nul, $nul, $nul);
    \Runtime\Crypto\bioFree($bio);

    return $x;
}

/** True when $pem holds a parseable certificate. */
function __mc_ossl_x509_valid(string $pem): bool
{
    \__mc_ossl_clear_errors();
    $x = \__mc_ossl_load_x509($pem);
    if ($x === 0) { return false; }
    \Runtime\Crypto\x509Free($x);

    return true;
}

/** A static C string (OBJ_nid2sn and friends) as a PHP string. */
function __mc_ossl_static_str(\Ffi\Ptr $p): string
{
    return \ptr_to_int($p) === 0 ? '' : \cstr_to_str($p);
}

/** The Name as php's subject/issuer array: DN order, repeated keys become lists. @return array<string,mixed> */
function __mc_ossl_x509_name(int $name, bool $shortNames): array
{
    $cell = \__mc_ossl_buf(16);
    $out = [];
    if (\Runtime\Crypto\x509NameDer($name, $cell, \ptr_offset($cell, 8)) === 1) {
        $der = \str_from_buffer(\int_to_ptr(\peek_i64($cell, 0)), \peek_i64($cell, 8));
        $seq = \__mc_der_tlv($der, 0);
        if (\count($seq) > 0) {
            $out = \__mc_der_name($der, $seq[1], $seq[2]);
        }
    }
    \Runtime\Libc\free($cell);
    if ($shortNames) { return $out; }
    /** @var array<string,mixed> $long */
    $long = [];
    foreach ($out as $k => $v) {
        $nid = \Runtime\Crypto\objSn2nid((string)$k);
        $ln = $nid === 0 ? '' : \__mc_ossl_static_str(\Runtime\Crypto\objNid2ln($nid));
        $long[$ln === '' ? (string)$k : $ln] = $v;
    }

    return $long;
}

/** UTCTime / GeneralizedTime text ("YYMMDDHHMMSSZ" or "YYYYMMDDHHMMSSZ") as a Unix time. */
function __mc_ossl_asn1_time(string $t): int
{
    if (\strlen($t) === 13) {
        $y = (int)\substr($t, 0, 2);
        $y = $y >= 50 ? 1900 + $y : 2000 + $y;
        $r = \substr($t, 2);
    } else {
        $y = (int)\substr($t, 0, 4);
        $r = \substr($t, 4);
    }

    return \gmmktime((int)\substr($r, 4, 2), (int)\substr($r, 6, 2), (int)\substr($r, 8, 2),
        (int)\substr($r, 0, 2), (int)\substr($r, 2, 2), $y);
}

/** The text of an ASN1_TIME* (YYMMDDHHMMSSZ), as php exposes validFrom / validTo. */
function __mc_ossl_time_str(int $t): string
{
    $p = \Runtime\Crypto\asn1StringData($t);

    return \ptr_to_int($p) === 0 ? '' : \cstr_to_str($p);
}

/**
 * openssl_x509_parse's array, key for key and in php's order, from a PEM certificate.
 *
 * @return array<string,mixed>|false
 */
function __mc_ossl_x509_parse(string $pem, bool $shortNames)
{
    \__mc_ossl_clear_errors();
    $x = \__mc_ossl_load_x509($pem);
    if ($x === 0) { return false; }
    $nul = \__mc_ossl_null();
    /** @var array<string,mixed> $o */
    $o = [];
    $subj = \Runtime\Crypto\x509SubjectName($x);
    $line = \__mc_ossl_buf(4096);
    $o['name'] = \__mc_ossl_static_str(\Runtime\Crypto\x509NameOneline($subj, $line, 4096));
    \Runtime\Libc\free($line);
    $o['subject'] = \__mc_ossl_x509_name($subj, $shortNames);
    $o['hash'] = \sprintf('%08x', \Runtime\Crypto\x509SubjectNameHash($x));
    $o['issuer'] = \__mc_ossl_x509_name(\Runtime\Crypto\x509IssuerName($x), $shortNames);
    $o['version'] = \Runtime\Crypto\x509Version($x);

    $bn = \Runtime\Crypto\asn1IntegerToBn(\Runtime\Crypto\x509Serial($x), $nul);
    $o['serialNumber'] = \__mc_ossl_take_cstr(\Runtime\Crypto\bnToDec($bn));
    $o['serialNumberHex'] = \__mc_ossl_take_cstr(\Runtime\Crypto\bnToHex($bn));
    \Runtime\Crypto\bnFree($bn);

    $from = \__mc_ossl_time_str(\Runtime\Crypto\x509NotBefore($x));
    $to = \__mc_ossl_time_str(\Runtime\Crypto\x509NotAfter($x));
    $o['validFrom'] = $from;
    $o['validTo'] = $to;
    $o['validFrom_time_t'] = \__mc_ossl_asn1_time($from);
    $o['validTo_time_t'] = \__mc_ossl_asn1_time($to);
    $sig = \Runtime\Crypto\x509SignatureNid($x);
    $o['signatureTypeSN'] = \__mc_ossl_static_str(\Runtime\Crypto\objNid2sn($sig));
    $o['signatureTypeLN'] = \__mc_ossl_static_str(\Runtime\Crypto\objNid2ln($sig));
    $o['signatureTypeNID'] = $sig;

    /** @var array<int,mixed> $purposes */
    $purposes = [];
    $np = \Runtime\Crypto\purposeCount();
    for ($i = 0; $i < $np; $i = $i + 1) {
        $pp = \Runtime\Crypto\purposeGet0($i);
        $id = \Runtime\Crypto\purposeId($pp);
        $nm = $shortNames ? \Runtime\Crypto\purposeSname($pp) : \Runtime\Crypto\purposeName($pp);
        $purposes[$id] = [
            \Runtime\Crypto\x509CheckPurpose($x, $id, 0) > 0,
            \Runtime\Crypto\x509CheckPurpose($x, $id, 1) > 0,
            \__mc_ossl_static_str($nm),
        ];
    }
    $o['purposes'] = $purposes;

    /** @var array<string,string> $exts */
    $exts = [];
    $ne = \Runtime\Crypto\x509ExtCount($x);
    $tb = \__mc_ossl_buf(128);
    for ($i = 0; $i < $ne; $i = $i + 1) {
        $ex = \Runtime\Crypto\x509Ext($x, $i);
        $obj = \Runtime\Crypto\extGetObject($ex);
        $nid = \Runtime\Crypto\objObj2nid($obj);
        if ($nid !== 0) {
            $key = \__mc_ossl_static_str(\Runtime\Crypto\objNid2sn($nid));
        } else {
            \Runtime\Crypto\objObj2txt($tb, 128, $obj, 1);
            $key = \cstr_to_str($tb);
        }
        $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
        \Runtime\Crypto\x509v3ExtPrint($mem, $ex, 0, 0);
        $exts[$key] = \__mc_ossl_bio_take($mem);
    }
    \Runtime\Libc\free($tb);
    $o['extensions'] = $exts;
    \Runtime\Crypto\x509Free($x);

    return $o;
}
