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

/**
 * OBJ_NAME_do_all_sorted callback: appends "name\n" to the buffer in $arg
 * ([used:i64][aliases:i64][bytes...]), skipping aliases unless asked for them.
 * Plain int return: it rides a void C slot and an exception must not escape it.
 */
function __mc_ossl_name_cb(\Ffi\Ptr $n, \Ffi\Ptr $arg): int
{
    if (\peek_i32($n, 4) !== 0 && \peek_i64($arg, 8) === 0) { return 0; }
    $name = \cstr_to_str(\int_to_ptr(\peek_i64($n, 8)));
    $used = \peek_i64($arg, 0);
    $len = \strlen($name);
    if ($used + $len + 1 > 65000) { return 0; }
    for ($i = 0; $i < $len; $i = $i + 1) {
        \poke_i8($arg, 16 + $used + $i, \ord($name[$i]));
    }
    \poke_i8($arg, 16 + $used + $len, 10);
    \poke_i64($arg, 0, $used + $len + 1);

    return 0;
}

/** Names of OBJ_NAME_TYPE $type (1 digests, 2 ciphers) in the registry's sorted order, as php lists them. @return string[] */
function __mc_ossl_registry_names(int $type, bool $aliases): array
{
    $buf = \__mc_ossl_buf(65536);
    \poke_i64($buf, 8, $aliases ? 1 : 0);
    \Runtime\Crypto\objNameDoAllSorted($type, \fn_to_ptr('__mc_ossl_name_cb'), $buf);
    $text = \str_from_buffer(\ptr_offset($buf, 16), \peek_i64($buf, 0));
    \Runtime\Libc\free($buf);
    $out = [];
    if ($text === '') { return $out; }
    foreach (\explode("\n", \substr($text, 0, \strlen($text) - 1)) as $n) { $out[] = $n; }

    return $out;
}

/** @return string[] */
function openssl_get_cipher_methods(bool $aliases = false): array
{
    return \__mc_ossl_registry_names(2, $aliases);
}

/** @return string[] */
function openssl_get_md_methods(bool $aliases = false): array
{
    return \__mc_ossl_registry_names(1, $aliases);
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
    $ccm = $mode === 7;
    $aead = $mode === 6 || $ccm || $mode === 0x10003 || ($flags & 0x200000) !== 0;
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
    if ($aead && !$enc && $tag === '') {
        throw new \ValueError($fn . '(): A tag must be given for AEAD decryption');
    }

    \__mc_ossl_clear_errors();
    $ctx = \Runtime\Crypto\cipherCtxNew();
    $nul = \__mc_ossl_null();
    $e = $enc ? 1 : 0;
    $ok = \Runtime\Crypto\cipherInit0($ctx, \int_to_ptr($cipher), $nul, $nul, $nul, $e) === 1;
    if ($ok && $aead) {
        $ok = \Runtime\Crypto\cipherCtrlPtr($ctx, 9, $ivGiven, $nul) === 1;
    }
    if ($ok && ($options & 2) !== 0) {
        \Runtime\Crypto\cipherSetPadding($ctx, 0);
    }
    if ($ok && $aead && ($ccm || $mode === 0x10003)) {
        // CCM and OCB take the tag length (and, decrypting, the tag) before the key.
        $ok = $enc ? \Runtime\Crypto\cipherCtrlPtr($ctx, 0x11, $tagLength, $nul) === 1
                   : \Runtime\Crypto\cipherCtrlStr($ctx, 0x11, \strlen($tag), $tag) === 1;
    }
    if ($ok) {
        $ok = \Runtime\Crypto\cipherInitKey($ctx, $nul, $nul, $key, $iv, $e) === 1;
    }
    if ($ok && $aead && !$enc && !$ccm && $mode !== 0x10003) {
        $ok = \Runtime\Crypto\cipherCtrlStr($ctx, 0x11, \strlen($tag), $tag) === 1;
    }
    $outl = \__mc_ossl_buf(8);
    $cap = \strlen($data) + \Runtime\Crypto\cipherBlockSize($cipher) + 16;
    $out = \__mc_ossl_buf($cap);
    $res = false;
    if ($ok && $ccm) {
        // CCM wants the total plaintext length announced before the AAD.
        $ok = \Runtime\Crypto\cipherUpdateLen($ctx, $nul, $outl, $nul, \strlen($data)) === 1;
    }
    if ($ok && $aead && $aad !== '') {
        $ok = \Runtime\Crypto\cipherUpdate($ctx, $nul, $outl, $aad, \strlen($aad)) === 1;
    }
    if ($ok) {
        $ok = \Runtime\Crypto\cipherUpdate($ctx, $out, $outl, $data, \strlen($data)) === 1;
    }
    if ($ok) {
        $n = \peek_u32($outl, 0);
        $head = \str_from_buffer($out, $n);
        // CCM has no final block: the tag check already happened in the update.
        $ok = $ccm ? true : \Runtime\Crypto\cipherFinal($ctx, $out, $outl) === 1;
        if ($ok) {
            $res = $ccm ? $head : $head . \str_from_buffer($out, \peek_u32($outl, 0));
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

/** DER element: $tag, definite length, content. */
function __mc_ossl_der(int $tag, string $c): string
{
    $n = \strlen($c);
    if ($n < 128) {
        $l = \chr($n);
    } else {
        $b = '';
        while ($n > 0) {
            $b = \chr($n & 255) . $b;
            $n = $n >> 8;
        }
        $l = \chr(0x80 | \strlen($b)) . $b;
    }

    return \chr($tag) . $l . $c;
}

/** DER INTEGER of an unsigned big-endian byte string. */
function __mc_ossl_der_uint(string $bin): string
{
    while (\strlen($bin) > 1 && \ord($bin[0]) === 0) { $bin = \substr($bin, 1); }
    if ($bin === '') { $bin = "\0"; }
    if ((\ord($bin[0]) & 0x80) !== 0) { $bin = "\0" . $bin; }

    return \__mc_ossl_der(2, $bin);
}

function __mc_ossl_pem(string $der, string $label): string
{
    $b = \base64_encode($der);
    $out = '-----BEGIN ' . $label . "-----\n";
    $n = \strlen($b);
    for ($i = 0; $i < $n; $i = $i + 64) {
        $out = $out . \substr($b, $i, 64) . "\n";
    }

    return $out . '-----END ' . $label . "-----\n";
}

/** PKCS#8 PEM (unencrypted) of an EVP_PKEY*, the key freed. */
function __mc_ossl_pkey_to_pem(int $k): string
{
    $nul = \__mc_ossl_null();
    $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
    \Runtime\Crypto\pemWritePkcs8Plain($mem, $k, $nul, $nul, 0, $nul, $nul);
    \Runtime\Crypto\pkeyFree($k);

    return \__mc_ossl_bio_take($mem);
}

/**
 * A fresh private key as unencrypted PKCS#8 PEM. $type is php's OPENSSL_KEYTYPE_*:
 * RSA, DSA, DH (needs $dhP/$dhG), EC, X25519, Ed25519, X448, Ed448. '' on failure.
 */
function __mc_ossl_pkey_new(int $type, int $bits, string $curve, string $dhP = '', string $dhG = ''): string
{
    \__mc_ossl_clear_errors();
    /** @var array<int,int> $ids */
    $ids = [0 => 6, 1 => 116, 2 => 28, 3 => 408, 4 => 1034, 5 => 1087, 6 => 1035, 7 => 1088];
    if (!isset($ids[$type])) {
        throw new \ValueError('openssl_pkey_new(): Argument #1 ($options) has an unknown private_key_type');
    }
    $nul = \__mc_ossl_null();
    $cell = \__mc_ossl_buf(8);
    $ctx = 0;
    if ($type === 1 || $type === 2) {
        // Parameters first (DSA generates them, DH takes the caller's prime and generator).
        $params = 0;
        if ($type === 1) {
            $pc = \Runtime\Crypto\pkeyCtxNewId(116, $nul);
            if ($pc !== 0 && \Runtime\Crypto\pkeyParamgenInit($pc) === 1
                && \Runtime\Crypto\pkeyCtxCtrl($pc, 116, 2, 0x1001, $bits, $nul) === 1
                && \Runtime\Crypto\pkeyParamgen($pc, $cell) === 1) {
                $params = \peek_i64($cell, 0);
            }
            if ($pc !== 0) { \Runtime\Crypto\pkeyCtxFree($pc); }
        } elseif ($dhP !== '' && $dhG !== '') {
            $der = \__mc_ossl_der(0x30, \__mc_ossl_der_uint($dhP) . \__mc_ossl_der_uint($dhG));
            $pem = \__mc_ossl_pem($der, 'DH PARAMETERS');
            $bio = \Runtime\Crypto\bioNewMemBuf($pem, \strlen($pem));
            $params = \Runtime\Crypto\pemReadParameters($bio, $nul);
            \Runtime\Crypto\bioFree($bio);
        }
        if ($params !== 0) {
            $ctx = \Runtime\Crypto\pkeyCtxNew($params, $nul);
            \Runtime\Crypto\pkeyFree($params);
        }
    } else {
        $ctx = \Runtime\Crypto\pkeyCtxNewId($ids[$type], $nul);
    }
    $k = 0;
    if ($ctx !== 0 && \Runtime\Crypto\pkeyKeygenInit($ctx) === 1) {
        $ok = true;
        if ($type === 0) {
            $ok = \Runtime\Crypto\pkeyCtxCtrl($ctx, 6, 4, 0x1003, $bits, $nul) === 1;
        } elseif ($type === 3) {
            $nid = \Runtime\Crypto\objTxt2nid($curve === '' ? 'prime256v1' : $curve);
            $ok = $nid !== 0 && \Runtime\Crypto\pkeyCtxCtrl($ctx, 408, 6, 0x1001, $nid, $nul) === 1;
        }
        // The paramgen step left its parameters key in the cell; keygen wants a NULL slot.
        \poke_i64($cell, 0, 0);
        if ($ok && \Runtime\Crypto\pkeyKeygen($ctx, $cell) === 1) { $k = \peek_i64($cell, 0); }
    }
    if ($ctx !== 0) { \Runtime\Crypto\pkeyCtxFree($ctx); }
    \Runtime\Libc\free($cell);
    if ($k === 0) { return ''; }

    return \__mc_ossl_pkey_to_pem($k);
}

/** Big-endian bytes of the BIGNUM key parameter $name, '' when the key has none. */
function __mc_ossl_bn_param(int $k, string $name): string
{
    $cell = \__mc_ossl_buf(8);
    $r = '';
    if (\Runtime\Crypto\pkeyBnParam($k, $name, $cell) === 1) {
        $bn = \peek_i64($cell, 0);
        $n = (\Runtime\Crypto\bnNumBits($bn) + 7) >> 3;
        $b = \__mc_ossl_buf($n + 1);
        \Runtime\Crypto\bnToBin($bn, $b);
        $r = \str_from_buffer($b, $n);
        \Runtime\Libc\free($b);
        \Runtime\Crypto\bnFree($bn);
    }
    \Runtime\Libc\free($cell);

    return $r;
}

/** An octet-string (or, with $utf8, a UTF-8 string) key parameter, '' when absent. */
function __mc_ossl_str_param(int $k, string $name, bool $utf8): string
{
    $buf = \__mc_ossl_buf(1024);
    $len = \__mc_ossl_buf(8);
    $r = '';
    $ok = $utf8 ? \Runtime\Crypto\pkeyUtf8Param($k, $name, $buf, 1024, $len)
                : \Runtime\Crypto\pkeyOctetParam($k, $name, $buf, 1024, $len);
    if ($ok === 1) { $r = \str_from_buffer($buf, \peek_i64($len, 0)); }
    \Runtime\Libc\free($buf);
    \Runtime\Libc\free($len);

    return $r;
}

/** SubjectPublicKeyInfo PEM of an EVP_PKEY* (the key is NOT freed). */
function __mc_ossl_pkey_pubpem(int $k): string
{
    $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
    \Runtime\Crypto\pemWritePubkey($mem, $k);

    return \__mc_ossl_bio_take($mem);
}

/**
 * openssl_pkey_get_details() for any key type, php's shape: bits, key, <type array>, type.
 * $pem is a private-key PEM when $isPriv, else something the public loader reads.
 *
 * @return array<string,mixed>|false
 */
function __mc_ossl_pkey_details(string $pem, bool $isPriv)
{
    \__mc_ossl_clear_errors();
    $k = $isPriv ? \__mc_ossl_load_priv($pem, '') : \__mc_ossl_load_pub($pem);
    if ($k === 0) { return false; }
    /** @var array<string,mixed> $o */
    $o = [];
    $o['bits'] = \Runtime\Crypto\pkeyBits($k);
    $o['key'] = \__mc_ossl_pkey_pubpem($k);
    $id = \Runtime\Crypto\pkeyBaseId($k);
    $type = -1;
    /** @var array<string,string> $sub */
    $sub = [];
    $name = '';
    if ($id === 6) {
        $type = 0;
        $name = 'rsa';
        foreach (['n' => 'n', 'e' => 'e', 'd' => 'd', 'p' => 'rsa-factor1', 'q' => 'rsa-factor2',
                  'dmp1' => 'rsa-exponent1', 'dmq1' => 'rsa-exponent2', 'iqmp' => 'rsa-coefficient1'] as $php => $ossl) {
            $v = \__mc_ossl_bn_param($k, $ossl);
            if ($v !== '') { $sub[$php] = $v; }
        }
    } elseif ($id === 116 || $id === 28) {
        $type = $id === 116 ? 1 : 2;
        $name = $id === 116 ? 'dsa' : 'dh';
        foreach ($id === 116 ? ['p' => 'p', 'q' => 'q', 'g' => 'g', 'priv_key' => 'priv', 'pub_key' => 'pub']
                             : ['p' => 'p', 'g' => 'g', 'priv_key' => 'priv', 'pub_key' => 'pub'] as $php => $ossl) {
            $v = \__mc_ossl_bn_param($k, $ossl);
            if ($v !== '') { $sub[$php] = $v; }
        }
    } elseif ($id === 408) {
        $type = 3;
        $name = 'ec';
        $curve = \__mc_ossl_str_param($k, 'group', true);
        $nid = \Runtime\Crypto\objTxt2nid($curve);
        $sub['curve_name'] = \__mc_ossl_static_str(\Runtime\Crypto\objNid2sn($nid));
        $oid = \__mc_ossl_buf(128);
        \Runtime\Crypto\objObj2txt($oid, 128, \Runtime\Crypto\objNid2obj($nid), 1);
        $sub['curve_oid'] = \cstr_to_str($oid);
        \Runtime\Libc\free($oid);
        foreach (['x' => 'qx', 'y' => 'qy', 'd' => 'priv'] as $php => $ossl) {
            $v = \__mc_ossl_bn_param($k, $ossl);
            if ($v !== '') { $sub[$php] = $v; }
        }
    } else {
        /** @var array<int,string> $names */
        $names = [1034 => 'x25519', 1087 => 'ed25519', 1035 => 'x448', 1088 => 'ed448'];
        if (isset($names[$id])) {
            $type = $id === 1034 ? 4 : ($id === 1087 ? 5 : ($id === 1035 ? 6 : 7));
            $name = $names[$id];
            $v = \__mc_ossl_str_param($k, 'priv', false);
            if ($v !== '') { $sub['priv_key'] = $v; }
            $v = \__mc_ossl_str_param($k, 'pub', false);
            if ($v !== '') { $sub['pub_key'] = $v; }
        }
    }
    \Runtime\Crypto\pkeyFree($k);
    if ($type < 0) { return false; }
    $o[$name] = $sub;
    $o['type'] = $type;

    return $o;
}

/** Shared secret of $privPem with the public key in $peerPem; false on failure. */
function __mc_ossl_derive(string $privPem, string $peerPem)
{
    \__mc_ossl_clear_errors();
    $priv = \__mc_ossl_load_priv($privPem, '');
    $peer = \__mc_ossl_load_pub($peerPem);
    $res = false;
    if ($priv !== 0 && $peer !== 0) {
        $nul = \__mc_ossl_null();
        $ctx = \Runtime\Crypto\pkeyCtxNew($priv, $nul);
        $lenCell = \__mc_ossl_buf(8);
        if ($ctx !== 0 && \Runtime\Crypto\pkeyDeriveInit($ctx) === 1
            && \Runtime\Crypto\pkeyDeriveSetPeer($ctx, $peer) === 1
            && \Runtime\Crypto\pkeyDerive($ctx, $nul, $lenCell) === 1) {
            $out = \__mc_ossl_buf(\peek_i64($lenCell, 0) + 1);
            if (\Runtime\Crypto\pkeyDerive($ctx, $out, $lenCell) === 1) {
                $res = \str_from_buffer($out, \peek_i64($lenCell, 0));
            }
            \Runtime\Libc\free($out);
        }
        \Runtime\Libc\free($lenCell);
        if ($ctx !== 0) { \Runtime\Crypto\pkeyCtxFree($ctx); }
    }
    if ($priv !== 0) { \Runtime\Crypto\pkeyFree($priv); }
    if ($peer !== 0) { \Runtime\Crypto\pkeyFree($peer); }

    return $res;
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
        $ok = \Runtime\Crypto\pkeyCtxCtrl($ctx, 6, -1, 0x1001, $padding, $nul) === 1;
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

/** Certificate PEM (with the text dump first unless $notext); '' when $pem is no certificate. */
function __mc_ossl_x509_export(string $pem, bool $notext): string
{
    \__mc_ossl_clear_errors();
    $x = \__mc_ossl_load_x509($pem);
    if ($x === 0) { return ''; }
    $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
    if (!$notext) { \Runtime\Crypto\x509Print($mem, $x); }
    \Runtime\Crypto\pemWriteX509($mem, $x);
    \Runtime\Crypto\x509Free($x);

    return \__mc_ossl_bio_take($mem);
}

/** X509_verify: 1 signed by the key, 0 not, -1 error. */
function __mc_ossl_x509_verify(string $certPem, string $pubPem): int
{
    \__mc_ossl_clear_errors();
    $x = \__mc_ossl_load_x509($certPem);
    $k = \__mc_ossl_load_pub($pubPem);
    $r = -1;
    if ($x !== 0 && $k !== 0) { $r = \Runtime\Crypto\x509Verify($x, $k); }
    if ($x !== 0) { \Runtime\Crypto\x509Free($x); }
    if ($k !== 0) { \Runtime\Crypto\pkeyFree($k); }

    return $r < 0 ? -1 : $r;
}

function __mc_ossl_x509_check_key(string $certPem, string $privPem): bool
{
    \__mc_ossl_clear_errors();
    $x = \__mc_ossl_load_x509($certPem);
    $k = \__mc_ossl_load_priv($privPem, '');
    $r = false;
    if ($x !== 0 && $k !== 0) { $r = \Runtime\Crypto\x509CheckPrivateKey($x, $k) === 1; }
    if ($x !== 0) { \Runtime\Crypto\x509Free($x); }
    if ($k !== 0) { \Runtime\Crypto\pkeyFree($k); }

    return $r;
}

/**
 * X509_verify_cert for purpose $purpose against the trust store built from $caInfo
 * (files / directories; the host default paths when empty). 1 ok, 0 rejected, -1 error.
 *
 * @param string[] $caInfo
 */
function __mc_ossl_x509_checkpurpose(string $certPem, int $purpose, array $caInfo, string $untrustedBundle): int
{
    \__mc_ossl_clear_errors();
    $x = \__mc_ossl_load_x509($certPem);
    if ($x === 0) { return -1; }
    $nul = \__mc_ossl_null();
    $store = \Runtime\Crypto\storeNew();
    if (\count($caInfo) === 0) {
        \Runtime\Crypto\storeSetDefaultPaths($store);
    }
    foreach ($caInfo as $p) {
        if (\is_dir($p)) { \Runtime\Crypto\storeLoadDir($store, $nul, $p); }
        else { \Runtime\Crypto\storeLoadFile($store, $p, $nul); }
    }
    $stack = 0;
    /** @var int[] $extra */
    $extra = [];
    if ($untrustedBundle !== '') {
        $stack = \Runtime\Crypto\skNewNull();
        $bio = \Runtime\Crypto\bioNewMemBuf($untrustedBundle, \strlen($untrustedBundle));
        while (true) {
            $u = \Runtime\Crypto\pemReadX509($bio, $nul, $nul, $nul);
            if ($u === 0) { break; }
            \Runtime\Crypto\skPush($stack, $u);
            $extra[] = $u;
        }
        \Runtime\Crypto\bioFree($bio);
        \__mc_ossl_clear_errors();
    }
    $ctx = \Runtime\Crypto\storeCtxNew();
    $r = -1;
    if (\Runtime\Crypto\storeCtxInit($ctx, $store, $x, $stack) === 1) {
        \Runtime\Crypto\storeCtxSetPurpose($ctx, $purpose);
        $v = \Runtime\Crypto\x509VerifyCert($ctx);
        $r = $v > 0 ? 1 : 0;
    }
    \Runtime\Crypto\storeCtxFree($ctx);
    if ($stack !== 0) { \Runtime\Crypto\skFree($stack); }
    foreach ($extra as $u) { \Runtime\Crypto\x509Free($u); }
    \Runtime\Crypto\storeFree($store);
    \Runtime\Crypto\x509Free($x);

    return $r;
}

// ── CSR and certificate issue ─────────────────────────────────────────────

/** Digest EVP_MD* for a php `digest_alg` option; ValueError when unknown. */
function __mc_ossl_digest_opt(string $fn, string $name): int
{
    return \__mc_ossl_md($fn, 3, 'options', $name);
}

/**
 * PKCS#10 request PEM for $dn (field => value, in order) signed by the private key.
 *
 * @param array<string,string> $dn
 */
function __mc_ossl_csr_new(array $dn, string $privPem, string $digest): string
{
    $md = \__mc_ossl_digest_opt('openssl_csr_new', $digest);
    \__mc_ossl_clear_errors();
    $k = \__mc_ossl_load_priv($privPem, '');
    if ($k === 0) { return ''; }
    $req = \Runtime\Crypto\reqNew();
    $name = \Runtime\Crypto\reqSubjectName($req);
    $ok = \Runtime\Crypto\reqSetVersion($req, 0) === 1;
    foreach ($dn as $field => $value) {
        if ($value === '') { continue; }
        if (\Runtime\Crypto\nameAddEntryByTxt($name, (string)$field, 0x1000, $value, -1, -1, 0) !== 1) {
            \Runtime\Crypto\reqFree($req);
            \Runtime\Crypto\pkeyFree($k);
            throw new \ValueError('openssl_csr_new(): Argument #1 ($distinguished_names) has an unknown field "' . $field . '"');
        }
    }
    $ok = $ok && \Runtime\Crypto\reqSetPubkey($req, $k) === 1 && \Runtime\Crypto\reqSign($req, $k, $md) > 0;
    $out = '';
    if ($ok) {
        $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
        \Runtime\Crypto\pemWriteReq($mem, $req);
        $out = \__mc_ossl_bio_take($mem);
    }
    \Runtime\Crypto\reqFree($req);
    \Runtime\Crypto\pkeyFree($k);

    return $out;
}

/** CSR PEM, preceded by the text dump unless $notext; '' when $pem is no request. */
function __mc_ossl_csr_export(string $pem, bool $notext): string
{
    \__mc_ossl_clear_errors();
    $nul = \__mc_ossl_null();
    $bio = \Runtime\Crypto\bioNewMemBuf($pem, \strlen($pem));
    $req = \Runtime\Crypto\pemReadReq($bio, $nul, $nul, $nul);
    \Runtime\Crypto\bioFree($bio);
    if ($req === 0) { return ''; }
    $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
    if (!$notext) { \Runtime\Crypto\reqPrint($mem, $req); }
    \Runtime\Crypto\pemWriteReq($mem, $req);
    \Runtime\Crypto\reqFree($req);

    return \__mc_ossl_bio_take($mem);
}

/** SubjectPublicKeyInfo PEM of a CSR's key; '' when $pem is no request. */
function __mc_ossl_csr_pubkey(string $pem): string
{
    \__mc_ossl_clear_errors();
    $nul = \__mc_ossl_null();
    $bio = \Runtime\Crypto\bioNewMemBuf($pem, \strlen($pem));
    $req = \Runtime\Crypto\pemReadReq($bio, $nul, $nul, $nul);
    \Runtime\Crypto\bioFree($bio);
    if ($req === 0) { return ''; }
    $k = \Runtime\Crypto\reqGetPubkey($req);
    $out = '';
    if ($k !== 0) {
        $out = \__mc_ossl_pkey_pubpem($k);
        \Runtime\Crypto\pkeyFree($k);
    }
    \Runtime\Crypto\reqFree($req);

    return $out;
}

/**
 * Certificate PEM issued for a CSR: signed by $privPem, issuer = $caPem's subject
 * (or the request's own subject when $caPem is ''). '' on failure.
 */
function __mc_ossl_csr_sign(string $csrPem, string $caPem, string $privPem, int $days, string $digest, int $serial): string
{
    $md = \__mc_ossl_md('openssl_csr_sign', 5, 'options', $digest);
    \__mc_ossl_clear_errors();
    $nul = \__mc_ossl_null();
    $bio = \Runtime\Crypto\bioNewMemBuf($csrPem, \strlen($csrPem));
    $req = \Runtime\Crypto\pemReadReq($bio, $nul, $nul, $nul);
    \Runtime\Crypto\bioFree($bio);
    $priv = \__mc_ossl_load_priv($privPem, '');
    $ca = $caPem === '' ? 0 : \__mc_ossl_load_x509($caPem);
    $out = '';
    $pub = 0;
    $x = 0;
    if ($req !== 0 && $priv !== 0 && ($caPem === '' || $ca !== 0)) {
        $pub = \Runtime\Crypto\reqGetPubkey($req);
        $ok = $pub !== 0 && \Runtime\Crypto\reqVerify($req, $pub) > 0;
        if ($ok && $ca !== 0) { $ok = \Runtime\Crypto\x509CheckPrivateKey($ca, $priv) === 1; }
        if ($ok) {
            $x = \Runtime\Crypto\x509New();
            $subject = \Runtime\Crypto\reqSubjectName($req);
            $ok = \Runtime\Crypto\x509SetVersion($x, 2) === 1
                && \Runtime\Crypto\asn1IntegerSet(\Runtime\Crypto\x509Serial($x), $serial) === 1
                && \Runtime\Crypto\x509SetSubjectName($x, $subject) === 1
                && \Runtime\Crypto\x509SetIssuerName($x, $ca !== 0 ? \Runtime\Crypto\x509SubjectName($ca) : $subject) === 1
                && \Runtime\Crypto\x509GmtimeAdj(\Runtime\Crypto\x509MNotBefore($x), 0) !== 0
                && \Runtime\Crypto\x509GmtimeAdj(\Runtime\Crypto\x509MNotAfter($x), $days * 86400) !== 0
                && \Runtime\Crypto\x509SetPubkey($x, $pub) === 1
                && \Runtime\Crypto\x509Sign($x, $priv, $md) > 0;
            if ($ok) {
                $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
                \Runtime\Crypto\pemWriteX509($mem, $x);
                $out = \__mc_ossl_bio_take($mem);
            }
        }
    }
    if ($x !== 0) { \Runtime\Crypto\x509Free($x); }
    if ($pub !== 0) { \Runtime\Crypto\pkeyFree($pub); }
    if ($ca !== 0) { \Runtime\Crypto\x509Free($ca); }
    if ($priv !== 0) { \Runtime\Crypto\pkeyFree($priv); }
    if ($req !== 0) { \Runtime\Crypto\reqFree($req); }

    return $out;
}

// ── SPKAC, curves, locations ──────────────────────────────────────────────

/** "SPKAC=<base64>" for a signed public key + challenge; '' on failure. */
function __mc_ossl_spki_new(string $privPem, string $challenge, string $digest): string
{
    $md = \__mc_ossl_md('openssl_spki_new', 3, 'digest_algo', $digest);
    \__mc_ossl_clear_errors();
    $k = \__mc_ossl_load_priv($privPem, '');
    if ($k === 0) { return ''; }
    $spki = \Runtime\Crypto\spkiNew();
    // NETSCAPE_SPKI { NETSCAPE_SPKAC *spkac; ... }, NETSCAPE_SPKAC { X509_PUBKEY *pubkey; ASN1_IA5STRING *challenge; }
    $challengeStr = \peek_i64(\int_to_ptr(\peek_i64(\int_to_ptr($spki), 0)), 8);
    $out = '';
    if (\Runtime\Crypto\asn1StringSet($challengeStr, $challenge, \strlen($challenge)) === 1
        && \Runtime\Crypto\spkiSetPubkey($spki, $k) === 1
        && \Runtime\Crypto\spkiSign($spki, $k, $md) > 0) {
        $p = \Runtime\Crypto\spkiB64Encode($spki);
        $out = \__mc_ossl_take_cstr($p);
        if (!\str_starts_with($out, 'SPKAC=')) { $out = 'SPKAC=' . $out; }
    }
    \Runtime\Crypto\spkiFree($spki);
    \Runtime\Crypto\pkeyFree($k);

    return $out;
}

/** NETSCAPE_SPKI* from base64 text (0 on failure); the caller frees it. */
function __mc_ossl_spki_load(string $b64): int
{
    \__mc_ossl_clear_errors();

    return \Runtime\Crypto\spkiB64Decode($b64, \strlen($b64));
}

function __mc_ossl_spki_verify(string $b64): bool
{
    $s = \__mc_ossl_spki_load($b64);
    if ($s === 0) { return false; }
    $k = \Runtime\Crypto\spkiGetPubkey($s);
    $r = $k !== 0 && \Runtime\Crypto\spkiVerify($s, $k) > 0;
    if ($k !== 0) { \Runtime\Crypto\pkeyFree($k); }
    \Runtime\Crypto\spkiFree($s);

    return $r;
}

/** Public key PEM of a SPKAC, or ''. */
function __mc_ossl_spki_export(string $b64): string
{
    $s = \__mc_ossl_spki_load($b64);
    if ($s === 0) { return ''; }
    $k = \Runtime\Crypto\spkiGetPubkey($s);
    $out = '';
    if ($k !== 0) {
        $out = \__mc_ossl_pkey_pubpem($k);
        \Runtime\Crypto\pkeyFree($k);
    }
    \Runtime\Crypto\spkiFree($s);

    return $out;
}

/** The challenge string of a SPKAC; false when it does not decode. @return string|false */
function __mc_ossl_spki_challenge(string $b64)
{
    $s = \__mc_ossl_spki_load($b64);
    if ($s === 0) { return false; }
    $c = \peek_i64(\int_to_ptr(\peek_i64(\int_to_ptr($s), 0)), 8);
    $r = \str_from_buffer(\Runtime\Crypto\asn1StringData($c), \Runtime\Crypto\asn1StringLength($c));
    \Runtime\Crypto\spkiFree($s);

    return $r;
}

/** @return string[] */
function openssl_get_curve_names(): array
{
    $n = \Runtime\Crypto\ecBuiltinCurves(\__mc_ossl_null(), 0);
    $out = [];
    if ($n < 1) { return $out; }
    $buf = \__mc_ossl_buf($n * 16);
    $got = \Runtime\Crypto\ecBuiltinCurves($buf, $n);
    for ($i = 0; $i < $got; $i = $i + 1) {
        $out[] = \__mc_ossl_static_str(\Runtime\Crypto\objNid2sn(\peek_i32($buf, $i * 16)));
    }
    \Runtime\Libc\free($buf);

    return $out;
}

/** @return array<string,string> */
function openssl_get_cert_locations(): array
{
    return [
        'default_cert_file' => \__mc_ossl_static_str(\Runtime\Crypto\defCertFile()),
        'default_cert_file_env' => \__mc_ossl_static_str(\Runtime\Crypto\defCertFileEnv()),
        'default_cert_dir' => \__mc_ossl_static_str(\Runtime\Crypto\defCertDir()),
        'default_cert_dir_env' => \__mc_ossl_static_str(\Runtime\Crypto\defCertDirEnv()),
        'default_private_dir' => \__mc_ossl_static_str(\Runtime\Crypto\defPrivateDir()),
        'default_default_cert_area' => \__mc_ossl_static_str(\Runtime\Crypto\defCertArea()),
        'ini_cafile' => '',
        'ini_capath' => '',
    ];
}

// ── PKCS#12 and PKCS#7 ────────────────────────────────────────────────────

/** X509* of every certificate in a PEM bundle; the caller frees them. @return int[] */
function __mc_ossl_pem_certs(string $bundle): array
{
    $nul = \__mc_ossl_null();
    $out = [];
    $bio = \Runtime\Crypto\bioNewMemBuf($bundle, \strlen($bundle));
    while (true) {
        $x = \Runtime\Crypto\pemReadX509($bio, $nul, $nul, $nul);
        if ($x === 0) { break; }
        $out[] = $x;
    }
    \Runtime\Crypto\bioFree($bio);
    \__mc_ossl_clear_errors();

    return $out;
}

/** STACK_OF(X509) holding borrowed pointers (free with skFree only). @param int[] $xs */
function __mc_ossl_stack_of(array $xs): int
{
    $sk = \Runtime\Crypto\skNewNull();
    foreach ($xs as $x) { \Runtime\Crypto\skPush($sk, $x); }

    return $sk;
}

/** @param int[] $xs */
function __mc_ossl_free_certs(array $xs): void
{
    foreach ($xs as $x) { \Runtime\Crypto\x509Free($x); }
}

/** PKCS#12 DER for cert + key (+ extra chain from $extraBundle); '' on failure. */
function __mc_ossl_p12_export(string $certPem, string $privPem, string $pass, string $friendly, string $extraBundle): string
{
    \__mc_ossl_clear_errors();
    $x = \__mc_ossl_load_x509($certPem);
    $k = \__mc_ossl_load_priv($privPem, '');
    $out = '';
    if ($x !== 0 && $k !== 0) {
        $extra = \__mc_ossl_pem_certs($extraBundle);
        $sk = \count($extra) === 0 ? 0 : \__mc_ossl_stack_of($extra);
        $p12 = $friendly === ''
            ? \Runtime\Crypto\p12CreateNoName($pass, \__mc_ossl_null(), $k, $x, $sk, 0, 0, 0, 0, 0)
            : \Runtime\Crypto\p12Create($pass, $friendly, $k, $x, $sk, 0, 0, 0, 0, 0);
        if ($p12 !== 0) {
            $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
            \Runtime\Crypto\i2dP12Bio($mem, $p12);
            $out = \__mc_ossl_bio_take($mem);
            \Runtime\Crypto\p12Free($p12);
        }
        if ($sk !== 0) { \Runtime\Crypto\skFree($sk); }
        \__mc_ossl_free_certs($extra);
    }
    if ($x !== 0) { \Runtime\Crypto\x509Free($x); }
    if ($k !== 0) { \Runtime\Crypto\pkeyFree($k); }

    return $out;
}

/**
 * Parse a PKCS#12 blob: ['cert' => PEM, 'pkey' => PEM, 'extracerts' => [PEM...]]; an empty
 * array on a bad blob or passphrase.
 *
 * @return array<string,mixed>
 */
function __mc_ossl_p12_read(string $der, string $pass): array
{
    \__mc_ossl_clear_errors();
    $nul = \__mc_ossl_null();
    $bio = \Runtime\Crypto\bioNewMemBuf($der, \strlen($der));
    $p12 = \Runtime\Crypto\d2iP12Bio($bio, $nul);
    \Runtime\Crypto\bioFree($bio);
    /** @var array<string,mixed> $res */
    $res = [];
    if ($p12 === 0) { return $res; }
    $cells = \__mc_ossl_buf(24);
    if (\Runtime\Crypto\p12Parse($p12, $pass, $cells, \ptr_offset($cells, 8), \ptr_offset($cells, 16)) === 1) {
        /** @var array<string,mixed> $o */
        $o = [];
        $x = \peek_i64($cells, 8);
        if ($x !== 0) {
            $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
            \Runtime\Crypto\pemWriteX509($mem, $x);
            $o['cert'] = \__mc_ossl_bio_take($mem);
            \Runtime\Crypto\x509Free($x);
        }
        $k = \peek_i64($cells, 0);
        if ($k !== 0) { $o['pkey'] = \__mc_ossl_pkey_to_pem($k); }
        $ca = \peek_i64($cells, 16);
        if ($ca !== 0) {
            /** @var string[] $list */
            $list = [];
            $n = \Runtime\Crypto\skNum($ca);
            for ($i = 0; $i < $n; $i = $i + 1) {
                $c = \Runtime\Crypto\skValue($ca, $i);
                $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
                \Runtime\Crypto\pemWriteX509($mem, $c);
                $list[] = \__mc_ossl_bio_take($mem);
            }
            $o['extracerts'] = $list;
            // ponytail: the chain's X509 objects are leaked here (sk_X509_pop_free needs a free callback pointer).
            \Runtime\Crypto\skFree($ca);
        }
        $res = $o;
    }
    \Runtime\Libc\free($cells);
    \Runtime\Crypto\p12Free($p12);

    return $res;
}

/** S-MIME PKCS#7 signature of $data; '' on failure. */
function __mc_ossl_p7_sign(string $data, string $certPem, string $privPem, string $extraBundle, int $flags): string
{
    \__mc_ossl_clear_errors();
    $x = \__mc_ossl_load_x509($certPem);
    $k = \__mc_ossl_load_priv($privPem, '');
    $out = '';
    if ($x !== 0 && $k !== 0) {
        $extra = \__mc_ossl_pem_certs($extraBundle);
        $sk = \count($extra) === 0 ? 0 : \__mc_ossl_stack_of($extra);
        $in = \Runtime\Crypto\bioNewMemBuf($data, \strlen($data));
        $p7 = \Runtime\Crypto\p7Sign($x, $k, $sk, $in, $flags);
        \Runtime\Crypto\bioFree($in);
        if ($p7 !== 0) {
            $in2 = \Runtime\Crypto\bioNewMemBuf($data, \strlen($data));
            $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
            \Runtime\Crypto\smimeWrite($mem, $p7, $in2, $flags);
            $out = \__mc_ossl_bio_take($mem);
            \Runtime\Crypto\bioFree($in2);
            \Runtime\Crypto\p7Free($p7);
        }
        if ($sk !== 0) { \Runtime\Crypto\skFree($sk); }
        \__mc_ossl_free_certs($extra);
    }
    if ($x !== 0) { \Runtime\Crypto\x509Free($x); }
    if ($k !== 0) { \Runtime\Crypto\pkeyFree($k); }

    return $out;
}

/** S-MIME PKCS#7 encryption of $data for every certificate in $certPems; '' on failure. @param string[] $certPems */
function __mc_ossl_p7_encrypt(string $data, array $certPems, string $cipher, int $flags): string
{
    \__mc_ossl_clear_errors();
    $c = \Runtime\Crypto\getCipherByName($cipher);
    /** @var int[] $xs */
    $xs = [];
    foreach ($certPems as $p) {
        $x = \__mc_ossl_load_x509($p);
        if ($x !== 0) { $xs[] = $x; }
    }
    $out = '';
    if ($c !== 0 && \count($xs) === \count($certPems) && \count($xs) > 0) {
        $sk = \__mc_ossl_stack_of($xs);
        $in = \Runtime\Crypto\bioNewMemBuf($data, \strlen($data));
        $p7 = \Runtime\Crypto\p7Encrypt($sk, $in, $c, $flags);
        \Runtime\Crypto\bioFree($in);
        if ($p7 !== 0) {
            $in2 = \Runtime\Crypto\bioNewMemBuf($data, \strlen($data));
            $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
            \Runtime\Crypto\smimeWrite($mem, $p7, $in2, $flags);
            $out = \__mc_ossl_bio_take($mem);
            \Runtime\Crypto\bioFree($in2);
            \Runtime\Crypto\p7Free($p7);
        }
        \Runtime\Crypto\skFree($sk);
    }
    \__mc_ossl_free_certs($xs);

    return $out;
}

/** Plaintext of an S-MIME PKCS#7 envelope; false on failure. @return string|false */
function __mc_ossl_p7_decrypt(string $smime, string $certPem, string $privPem)
{
    \__mc_ossl_clear_errors();
    $nul = \__mc_ossl_null();
    $x = \__mc_ossl_load_x509($certPem);
    $k = \__mc_ossl_load_priv($privPem, '');
    $res = false;
    if ($x !== 0 && $k !== 0) {
        $in = \Runtime\Crypto\bioNewMemBuf($smime, \strlen($smime));
        $p7 = \Runtime\Crypto\smimeRead($in, $nul);
        \Runtime\Crypto\bioFree($in);
        if ($p7 !== 0) {
            $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
            if (\Runtime\Crypto\p7Decrypt($p7, $k, $x, $mem, 0) === 1) {
                $res = \__mc_ossl_bio_take($mem);
            } else {
                \Runtime\Crypto\bioFree($mem);
            }
            \Runtime\Crypto\p7Free($p7);
        }
    }
    if ($x !== 0) { \Runtime\Crypto\x509Free($x); }
    if ($k !== 0) { \Runtime\Crypto\pkeyFree($k); }

    return $res;
}

/**
 * Verify an S-MIME signed message. Answers [status, content, signersPem] with status
 * 1 valid / 0 invalid / -1 unreadable; $detached is the content for a detached signature.
 *
 * @param string[] $caInfo
 * @return array<int,mixed>
 */
function __mc_ossl_p7_verify(string $smime, int $flags, array $caInfo, string $untrusted, string $detached): array
{
    \__mc_ossl_clear_errors();
    $nul = \__mc_ossl_null();
    $in = \Runtime\Crypto\bioNewMemBuf($smime, \strlen($smime));
    $cell = \__mc_ossl_buf(8);
    $p7 = \Runtime\Crypto\smimeRead($in, $cell);
    $bcont = \peek_i64($cell, 0);
    \Runtime\Libc\free($cell);
    \Runtime\Crypto\bioFree($in);
    $status = -1;
    $content = '';
    $signers = '';
    if ($p7 !== 0) {
        $store = \Runtime\Crypto\storeNew();
        if (\count($caInfo) === 0) { \Runtime\Crypto\storeSetDefaultPaths($store); }
        foreach ($caInfo as $p) {
            if (\is_dir($p)) { \Runtime\Crypto\storeLoadDir($store, $nul, $p); }
            else { \Runtime\Crypto\storeLoadFile($store, $p, $nul); }
        }
        $extra = \__mc_ossl_pem_certs($untrusted);
        $sk = \count($extra) === 0 ? 0 : \__mc_ossl_stack_of($extra);
        $indata = $bcont;
        $dbio = 0;
        if ($detached !== '') {
            $dbio = \Runtime\Crypto\bioNewMemBuf($detached, \strlen($detached));
            $indata = $dbio;
        }
        $out = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
        $r = \Runtime\Crypto\p7Verify($p7, $sk, $store, $indata, $out, $flags);
        $status = $r > 0 ? 1 : 0;
        $content = \__mc_ossl_bio_take($out);
        if ($status === 1) {
            $sg = \Runtime\Crypto\p7Signers($p7, $sk, $flags);
            if ($sg !== 0) {
                $n = \Runtime\Crypto\skNum($sg);
                for ($i = 0; $i < $n; $i = $i + 1) {
                    $mem = \Runtime\Crypto\bioNew(\Runtime\Crypto\bioSMem());
                    \Runtime\Crypto\pemWriteX509($mem, \Runtime\Crypto\skValue($sg, $i));
                    $signers = $signers . \__mc_ossl_bio_take($mem);
                }
                \Runtime\Crypto\skFree($sg);
            }
        }
        if ($dbio !== 0) { \Runtime\Crypto\bioFree($dbio); }
        if ($sk !== 0) { \Runtime\Crypto\skFree($sk); }
        \__mc_ossl_free_certs($extra);
        \Runtime\Crypto\storeFree($store);
        \Runtime\Crypto\p7Free($p7);
    }
    if ($bcont !== 0) { \Runtime\Crypto\bioFree($bcont); }

    return [$status, $content, $signers];
}