<?php

namespace Runtime\Crypto;

use Ffi\CType;
use Ffi\Library;
use Ffi\Ptr;
use Ffi\Symbol;

// Thin FFI binding to the host libcrypto (OpenSSL 3.x) for the hashing surface —
// md5/sha1/sha256/…/hash and hash_hmac. libcrypto is ALREADY on every
// stdlib-linked program's link line (openssl_link_flags() = `-lssl -lcrypto` in
// Manticore\Main), so these resolve with no new link infra; dead-strip drops
// libcrypto for a program that hashes nothing, and the cold seed auto-stubs the
// unlinked symbols (tools/link_stubs.sh).
//
// The one-shot EVP_Digest()/HMAC() APIs are used on purpose: they take the whole
// message in one call and need NO EVP_MD_CTX, which OpenSSL 3.x keeps OPAQUE — so
// there is no struct to allocate or an offset to poke (the same "no offset table"
// property Runtime\Openssl relies on). An `const EVP_MD *` (from EVP_sha256() etc.)
// is carried as a raw i64 `int`, exactly like Openssl's SSL_METHOD*.

// const EVP_MD *EVP_md5(void) / _sha1 / _sha256 / _sha384 / _sha512 — the digest
// selector objects; opaque, carried as raw i64.
#[Library('crypto'), Symbol('EVP_md5')]
function md5(): int {}
#[Library('crypto'), Symbol('EVP_sha1')]
function sha1(): int {}
#[Library('crypto'), Symbol('EVP_sha224')]
function sha224(): int {}
#[Library('crypto'), Symbol('EVP_sha256')]
function sha256(): int {}
#[Library('crypto'), Symbol('EVP_sha384')]
function sha384(): int {}
#[Library('crypto'), Symbol('EVP_sha512')]
function sha512(): int {}

// int EVP_Digest(const void *data, size_t count, unsigned char *md,
//                unsigned int *size, const EVP_MD *type, ENGINE *impl);
// One-shot hash of $data into the $md buffer; $size receives the digest length,
// $impl is NULL. Returns 1 on success.
#[Library('crypto'), Symbol('EVP_Digest')]
function evpDigest(string $data, #[CType('size_t')] int $count, Ptr $md, Ptr $size,
                   int $type, Ptr $impl): int {}

// unsigned char *HMAC(const EVP_MD *evp_md, const void *key, int key_len,
//                     const unsigned char *d, size_t n,
//                     unsigned char *md, unsigned int *md_len);
// One-shot HMAC of $d under $key into $md; $md_len receives the length. Returns
// the $md pointer (non-NULL) on success — we read the buffer, not the return.
#[Library('crypto'), Symbol('HMAC')]
function hmac(int $evp_md, string $key, #[CType('int')] int $key_len,
              string $d, #[CType('size_t')] int $n, Ptr $md, Ptr $md_len): Ptr {}

// ── ext/openssl: ciphers, keys, signatures, certificates ──
// Every handle (EVP_CIPHER*, EVP_PKEY*, BIO*, X509*, ...) is carried as a raw i64
// `int`, 0 = NULL, exactly like the digest selectors above. A C `int` result is
// marked CType('int') so a -1 sign-extends. A parameter that is sometimes NULL
// and sometimes a buffer is bound twice under two PHP names (`...0` = Ptr).

#[Library('crypto'), Symbol('EVP_get_cipherbyname')]
function getCipherByName(string $name): int {}
#[Library('crypto'), Symbol('EVP_get_digestbyname')]
function getDigestByName(string $name): int {}
#[Library('crypto'), Symbol('EVP_CIPHER_get_iv_length'), CType('int')]
function cipherIvLen(int $cipher): int {}
#[Library('crypto'), Symbol('EVP_CIPHER_get_key_length'), CType('int')]
function cipherKeyLen(int $cipher): int {}
#[Library('crypto'), Symbol('EVP_CIPHER_get_block_size'), CType('int')]
function cipherBlockSize(int $cipher): int {}
#[Library('crypto'), Symbol('EVP_CIPHER_get_flags')]
function cipherFlags(int $cipher): int {}

#[Library('crypto'), Symbol('EVP_CIPHER_CTX_new')]
function cipherCtxNew(): int {}
#[Library('crypto'), Symbol('EVP_CIPHER_CTX_free')]
function cipherCtxFree(int $ctx): void {}
// EVP_CipherInit_ex(ctx, cipher, NULL, NULL, NULL, enc) then (ctx, NULL, NULL, key, iv, enc)
#[Library('crypto'), Symbol('EVP_CipherInit_ex'), CType('int')]
function cipherInit0(int $ctx, Ptr $cipher, Ptr $impl, Ptr $key, Ptr $iv, #[CType('int')] int $enc): int {}
#[Library('crypto'), Symbol('EVP_CipherInit_ex'), CType('int')]
function cipherInitKey(int $ctx, Ptr $cipher, Ptr $impl, string $key, string $iv, #[CType('int')] int $enc): int {}
#[Library('crypto'), Symbol('EVP_CIPHER_CTX_set_padding'), CType('int')]
function cipherSetPadding(int $ctx, #[CType('int')] int $pad): int {}
#[Library('crypto'), Symbol('EVP_CipherUpdate'), CType('int')]
function cipherUpdate(int $ctx, Ptr $out, Ptr $outl, string $in, #[CType('int')] int $inl): int {}
#[Library('crypto'), Symbol('EVP_CipherFinal_ex'), CType('int')]
function cipherFinal(int $ctx, Ptr $out, Ptr $outl): int {}
#[Library('crypto'), Symbol('EVP_CIPHER_CTX_ctrl'), CType('int')]
function cipherCtrlPtr(int $ctx, #[CType('int')] int $type, #[CType('int')] int $arg, Ptr $p): int {}
#[Library('crypto'), Symbol('EVP_CIPHER_CTX_ctrl'), CType('int')]
function cipherCtrlStr(int $ctx, #[CType('int')] int $type, #[CType('int')] int $arg, string $p): int {}

// ── errors ──
#[Library('crypto'), Symbol('ERR_get_error')]
function errGetError(): int {}
#[Library('crypto'), Symbol('ERR_error_string_n')]
function errErrorStringN(int $e, Ptr $buf, #[CType('size_t')] int $len): void {}

// ── BIO (memory) ──
#[Library('crypto'), Symbol('BIO_new_mem_buf')]
function bioNewMemBuf(string $buf, #[CType('int')] int $len): int {}
#[Library('crypto'), Symbol('BIO_s_mem')]
function bioSMem(): int {}
#[Library('crypto'), Symbol('BIO_new')]
function bioNew(int $method): int {}
#[Library('crypto'), Symbol('BIO_free'), CType('int')]
function bioFree(int $bio): int {}
#[Library('crypto'), Symbol('BIO_read'), CType('int')]
function bioRead(int $bio, Ptr $buf, #[CType('int')] int $n): int {}

// ── keys ──
#[Library('crypto'), Symbol('PEM_read_bio_PrivateKey')]
function pemReadPrivateKey(int $bio, Ptr $x, Ptr $cb, string $u): int {}
#[Library('crypto'), Symbol('PEM_read_bio_PUBKEY')]
function pemReadPubkey(int $bio, Ptr $x, Ptr $cb, Ptr $u): int {}
#[Library('crypto'), Symbol('PEM_read_bio_X509')]
function pemReadX509(int $bio, Ptr $x, Ptr $cb, Ptr $u): int {}
#[Library('crypto'), Symbol('PEM_write_bio_PUBKEY'), CType('int')]
function pemWritePubkey(int $bio, int $pkey): int {}
#[Library('crypto'), Symbol('PEM_write_bio_PKCS8PrivateKey'), CType('int')]
function pemWritePkcs8Plain(int $bio, int $pkey, Ptr $enc, Ptr $kstr, #[CType('int')] int $klen, Ptr $cb, Ptr $u): int {}
#[Library('crypto'), Symbol('PEM_write_bio_PKCS8PrivateKey'), CType('int')]
function pemWritePkcs8Enc(int $bio, int $pkey, Ptr $enc, string $kstr, #[CType('int')] int $klen, Ptr $cb, Ptr $u): int {}
#[Library('crypto'), Symbol('EVP_PKEY_free')]
function pkeyFree(int $pkey): void {}
// EVP_RSA_gen / EVP_EC_gen are macros over a variadic call, not symbols: generate
// through an EVP_PKEY_CTX instead.
#[Library('crypto'), Symbol('EVP_PKEY_CTX_new_id')]
function pkeyCtxNewId(#[CType('int')] int $id, Ptr $engine): int {}
#[Library('crypto'), Symbol('EVP_PKEY_keygen_init'), CType('int')]
function pkeyKeygenInit(int $ctx): int {}
#[Library('crypto'), Symbol('EVP_PKEY_keygen'), CType('int')]
function pkeyKeygen(int $ctx, Ptr $ppkey): int {}
#[Library('crypto'), Symbol('EVP_PKEY_CTX_set_rsa_keygen_bits'), CType('int')]
function pkeyCtxSetRsaKeygenBits(int $ctx, #[CType('int')] int $bits): int {}
#[Library('crypto'), Symbol('EVP_PKEY_CTX_set_ec_paramgen_curve_nid'), CType('int')]
function pkeyCtxSetEcCurveNid(int $ctx, #[CType('int')] int $nid): int {}
#[Library('crypto'), Symbol('OBJ_txt2nid'), CType('int')]
function objTxt2nid(string $s): int {}
#[Library('crypto'), Symbol('X509_get0_pubkey')]
function x509Pubkey(int $x): int {}

// ── sign / verify ──
#[Library('crypto'), Symbol('EVP_MD_CTX_new')]
function mdCtxNew(): int {}
#[Library('crypto'), Symbol('EVP_MD_CTX_free')]
function mdCtxFree(int $ctx): void {}
#[Library('crypto'), Symbol('EVP_DigestSignInit'), CType('int')]
function digestSignInit(int $ctx, Ptr $pctx, int $md, Ptr $engine, int $pkey): int {}
#[Library('crypto'), Symbol('EVP_DigestSign'), CType('int')]
function digestSign(int $ctx, Ptr $sig, Ptr $siglen, string $tbs, #[CType('size_t')] int $tbslen): int {}
#[Library('crypto'), Symbol('EVP_DigestVerifyInit'), CType('int')]
function digestVerifyInit(int $ctx, Ptr $pctx, int $md, Ptr $engine, int $pkey): int {}
#[Library('crypto'), Symbol('EVP_DigestVerify'), CType('int')]
function digestVerify(int $ctx, string $sig, #[CType('size_t')] int $siglen, string $tbs, #[CType('size_t')] int $tbslen): int {}

// ── raw RSA (EVP_PKEY_CTX) ──
#[Library('crypto'), Symbol('EVP_PKEY_CTX_new')]
function pkeyCtxNew(int $pkey, Ptr $engine): int {}
#[Library('crypto'), Symbol('EVP_PKEY_CTX_free')]
function pkeyCtxFree(int $ctx): void {}
#[Library('crypto'), Symbol('EVP_PKEY_CTX_set_rsa_padding'), CType('int')]
function pkeyCtxSetRsaPadding(int $ctx, #[CType('int')] int $pad): int {}
#[Library('crypto'), Symbol('EVP_PKEY_encrypt_init'), CType('int')]
function pkeyEncryptInit(int $ctx): int {}
#[Library('crypto'), Symbol('EVP_PKEY_encrypt'), CType('int')]
function pkeyEncrypt(int $ctx, Ptr $out, Ptr $outlen, string $in, #[CType('size_t')] int $inlen): int {}
#[Library('crypto'), Symbol('EVP_PKEY_decrypt_init'), CType('int')]
function pkeyDecryptInit(int $ctx): int {}
#[Library('crypto'), Symbol('EVP_PKEY_decrypt'), CType('int')]
function pkeyDecrypt(int $ctx, Ptr $out, Ptr $outlen, string $in, #[CType('size_t')] int $inlen): int {}
#[Library('crypto'), Symbol('EVP_PKEY_sign_init'), CType('int')]
function pkeySignInit(int $ctx): int {}
#[Library('crypto'), Symbol('EVP_PKEY_sign'), CType('int')]
function pkeySign(int $ctx, Ptr $sig, Ptr $siglen, string $tbs, #[CType('size_t')] int $tbslen): int {}
#[Library('crypto'), Symbol('EVP_PKEY_verify_recover_init'), CType('int')]
function pkeyVerifyRecoverInit(int $ctx): int {}
#[Library('crypto'), Symbol('EVP_PKEY_verify_recover'), CType('int')]
function pkeyVerifyRecover(int $ctx, Ptr $out, Ptr $outlen, string $sig, #[CType('size_t')] int $siglen): int {}

// ── X.509 ──
#[Library('crypto'), Symbol('X509_free')]
function x509Free(int $x): void {}
#[Library('crypto'), Symbol('X509_get_subject_name')]
function x509SubjectName(int $x): int {}
#[Library('crypto'), Symbol('X509_get_issuer_name')]
function x509IssuerName(int $x): int {}
#[Library('crypto'), Symbol('X509_NAME_oneline')]
function x509NameOneline(int $name, Ptr $buf, #[CType('int')] int $size): Ptr {}
// int X509_NAME_get0_der(const X509_NAME*, const unsigned char **pder, size_t *pderlen)
#[Library('crypto'), Symbol('X509_NAME_get0_der'), CType('int')]
function x509NameDer(int $name, Ptr $pder, Ptr $plen): int {}
#[Library('crypto'), Symbol('X509_subject_name_hash')]
function x509SubjectNameHash(int $x): int {}
#[Library('crypto'), Symbol('X509_get_version')]
function x509Version(int $x): int {}
#[Library('crypto'), Symbol('X509_get_serialNumber')]
function x509Serial(int $x): int {}
#[Library('crypto'), Symbol('ASN1_INTEGER_to_BN')]
function asn1IntegerToBn(int $ai, Ptr $bn): int {}
#[Library('crypto'), Symbol('BN_bn2dec')]
function bnToDec(int $bn): Ptr {}
#[Library('crypto'), Symbol('BN_bn2hex')]
function bnToHex(int $bn): Ptr {}
#[Library('crypto'), Symbol('BN_free')]
function bnFree(int $bn): void {}
#[Library('crypto'), Symbol('CRYPTO_free')]
function cryptoFree(Ptr $p, string $file, #[CType('int')] int $line): void {}
#[Library('crypto'), Symbol('X509_get0_notBefore')]
function x509NotBefore(int $x): int {}
#[Library('crypto'), Symbol('X509_get0_notAfter')]
function x509NotAfter(int $x): int {}
#[Library('crypto'), Symbol('ASN1_STRING_get0_data')]
function asn1StringData(int $s): Ptr {}
#[Library('crypto'), Symbol('X509_get_signature_nid'), CType('int')]
function x509SignatureNid(int $x): int {}
#[Library('crypto'), Symbol('OBJ_nid2sn')]
function objNid2sn(#[CType('int')] int $nid): Ptr {}
#[Library('crypto'), Symbol('OBJ_nid2ln')]
function objNid2ln(#[CType('int')] int $nid): Ptr {}
#[Library('crypto'), Symbol('OBJ_sn2nid'), CType('int')]
function objSn2nid(string $sn): int {}
#[Library('crypto'), Symbol('OBJ_obj2nid'), CType('int')]
function objObj2nid(int $obj): int {}
#[Library('crypto'), Symbol('OBJ_obj2txt'), CType('int')]
function objObj2txt(Ptr $buf, #[CType('int')] int $len, int $obj, #[CType('int')] int $noName): int {}
#[Library('crypto'), Symbol('X509_get_ext_count'), CType('int')]
function x509ExtCount(int $x): int {}
#[Library('crypto'), Symbol('X509_get_ext')]
function x509Ext(int $x, #[CType('int')] int $loc): int {}
#[Library('crypto'), Symbol('X509_EXTENSION_get_object')]
function extGetObject(int $ex): int {}
#[Library('crypto'), Symbol('X509V3_EXT_print'), CType('int')]
function x509v3ExtPrint(int $bio, int $ex, #[CType('unsigned long')] int $flag, #[CType('int')] int $indent): int {}
#[Library('crypto'), Symbol('X509_PURPOSE_get_count'), CType('int')]
function purposeCount(): int {}
#[Library('crypto'), Symbol('X509_PURPOSE_get0')]
function purposeGet0(#[CType('int')] int $idx): int {}
#[Library('crypto'), Symbol('X509_PURPOSE_get_id'), CType('int')]
function purposeId(int $pp): int {}
#[Library('crypto'), Symbol('X509_PURPOSE_get0_name')]
function purposeName(int $pp): Ptr {}
#[Library('crypto'), Symbol('X509_PURPOSE_get0_sname')]
function purposeSname(int $pp): Ptr {}
#[Library('crypto'), Symbol('X509_check_purpose'), CType('int')]
function x509CheckPurpose(int $x, #[CType('int')] int $id, #[CType('int')] int $ca): int {}
