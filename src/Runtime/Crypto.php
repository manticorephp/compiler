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

// ── slice 2: key parameters, derive, paramgen, verification ──
// Everything below is OpenSSL >= 3.0 (EVP_PKEY_get_* names, *_param accessors).

#[Library('crypto'), Symbol('EVP_PKEY_get_bits'), CType('int')]
function pkeyBits(int $pkey): int {}
#[Library('crypto'), Symbol('EVP_PKEY_get_base_id'), CType('int')]
function pkeyBaseId(int $pkey): int {}
#[Library('crypto'), Symbol('EVP_PKEY_get_bn_param'), CType('int')]
function pkeyBnParam(int $pkey, string $name, Ptr $bnOut): int {}
#[Library('crypto'), Symbol('EVP_PKEY_get_octet_string_param'), CType('int')]
function pkeyOctetParam(int $pkey, string $name, Ptr $buf, #[CType('size_t')] int $max, Ptr $outLen): int {}
#[Library('crypto'), Symbol('EVP_PKEY_get_utf8_string_param'), CType('int')]
function pkeyUtf8Param(int $pkey, string $name, Ptr $buf, #[CType('size_t')] int $max, Ptr $outLen): int {}
#[Library('crypto'), Symbol('BN_num_bits'), CType('int')]
function bnNumBits(int $bn): int {}
#[Library('crypto'), Symbol('BN_bn2bin'), CType('int')]
function bnToBin(int $bn, Ptr $to): int {}
#[Library('crypto'), Symbol('BN_bin2bn')]
function bnFromBin(string $s, #[CType('int')] int $len, Ptr $ret): int {}

#[Library('crypto'), Symbol('EVP_PKEY_derive_init'), CType('int')]
function pkeyDeriveInit(int $ctx): int {}
#[Library('crypto'), Symbol('EVP_PKEY_derive_set_peer'), CType('int')]
function pkeyDeriveSetPeer(int $ctx, int $peer): int {}
#[Library('crypto'), Symbol('EVP_PKEY_derive'), CType('int')]
function pkeyDerive(int $ctx, Ptr $out, Ptr $outLen): int {}
#[Library('crypto'), Symbol('EVP_PKEY_paramgen_init'), CType('int')]
function pkeyParamgenInit(int $ctx): int {}
#[Library('crypto'), Symbol('EVP_PKEY_paramgen'), CType('int')]
function pkeyParamgen(int $ctx, Ptr $ppkey): int {}
// int EVP_PKEY_CTX_ctrl(ctx, keytype, optype, cmd, p1, void *p2) — the form that
// exists as a function on 1.1.1 and 3.x alike (the DSA bits setter is a macro on 1.1.1).
#[Library('crypto'), Symbol('EVP_PKEY_CTX_ctrl'), CType('int')]
function pkeyCtxCtrl(int $ctx, #[CType('int')] int $keytype, #[CType('int')] int $optype,
                     #[CType('int')] int $cmd, #[CType('int')] int $p1, Ptr $p2): int {}
#[Library('crypto'), Symbol('PEM_read_bio_Parameters')]
function pemReadParameters(int $bio, Ptr $x): int {}

#[Library('crypto'), Symbol('PEM_write_bio_X509'), CType('int')]
function pemWriteX509(int $bio, int $x): int {}
#[Library('crypto'), Symbol('X509_print'), CType('int')]
function x509Print(int $bio, int $x): int {}
#[Library('crypto'), Symbol('X509_verify'), CType('int')]
function x509Verify(int $x, int $pkey): int {}
#[Library('crypto'), Symbol('X509_check_private_key'), CType('int')]
function x509CheckPrivateKey(int $x, int $pkey): int {}

#[Library('crypto'), Symbol('OPENSSL_sk_new_null')]
function skNewNull(): int {}
#[Library('crypto'), Symbol('OPENSSL_sk_push'), CType('int')]
function skPush(int $sk, int $p): int {}
#[Library('crypto'), Symbol('OPENSSL_sk_free')]
function skFree(int $sk): void {}
#[Library('crypto'), Symbol('OPENSSL_sk_num'), CType('int')]
function skNum(int $sk): int {}
#[Library('crypto'), Symbol('OPENSSL_sk_value')]
function skValue(int $sk, #[CType('int')] int $i): int {}

#[Library('crypto'), Symbol('X509_STORE_new')]
function storeNew(): int {}
#[Library('crypto'), Symbol('X509_STORE_free')]
function storeFree(int $s): void {}
#[Library('crypto'), Symbol('X509_STORE_add_cert'), CType('int')]
function storeAddCert(int $s, int $x): int {}
#[Library('crypto'), Symbol('X509_STORE_set_default_paths'), CType('int')]
function storeSetDefaultPaths(int $s): int {}
#[Library('crypto'), Symbol('X509_STORE_load_locations'), CType('int')]
function storeLoadFile(int $s, string $file, Ptr $dir): int {}
#[Library('crypto'), Symbol('X509_STORE_load_locations'), CType('int')]
function storeLoadDir(int $s, Ptr $file, string $dir): int {}
#[Library('crypto'), Symbol('X509_STORE_CTX_new')]
function storeCtxNew(): int {}
#[Library('crypto'), Symbol('X509_STORE_CTX_free')]
function storeCtxFree(int $c): void {}
#[Library('crypto'), Symbol('X509_STORE_CTX_init'), CType('int')]
function storeCtxInit(int $c, int $store, int $x, int $untrusted): int {}
#[Library('crypto'), Symbol('X509_STORE_CTX_set_purpose'), CType('int')]
function storeCtxSetPurpose(int $c, #[CType('int')] int $purpose): int {}
#[Library('crypto'), Symbol('X509_verify_cert'), CType('int')]
function x509VerifyCert(int $c): int {}
#[Library('crypto'), Symbol('OBJ_nid2obj')]
function objNid2obj(#[CType('int')] int $nid): int {}
#[Library('crypto'), Symbol('EVP_CipherUpdate'), CType('int')]
function cipherUpdateLen(int $ctx, Ptr $out, Ptr $outl, Ptr $in, #[CType('int')] int $inl): int {}
// void OBJ_NAME_do_all_sorted(int type, void (*fn)(const OBJ_NAME *, void *arg), void *arg) — 1.1.1 and 3.x
#[Library('crypto'), Symbol('OBJ_NAME_do_all_sorted')]
function objNameDoAllSorted(#[CType('int')] int $type, Ptr $fn, Ptr $arg): void {}

// ── slice 2: CSR, certificate issue, SPKAC, curves, PKCS#12, PKCS#7 ──
// All present on OpenSSL 1.1.1 and 3.x.

#[Library('crypto'), Symbol('X509_REQ_new')]
function reqNew(): int {}
#[Library('crypto'), Symbol('X509_REQ_free')]
function reqFree(int $req): void {}
#[Library('crypto'), Symbol('X509_REQ_set_version'), CType('int')]
function reqSetVersion(int $req, #[CType('long')] int $version): int {}
#[Library('crypto'), Symbol('X509_REQ_set_pubkey'), CType('int')]
function reqSetPubkey(int $req, int $pkey): int {}
#[Library('crypto'), Symbol('X509_REQ_get_subject_name')]
function reqSubjectName(int $req): int {}
#[Library('crypto'), Symbol('X509_NAME_add_entry_by_txt'), CType('int')]
function nameAddEntryByTxt(int $name, string $field, #[CType('int')] int $type, string $bytes,
                           #[CType('int')] int $len, #[CType('int')] int $loc, #[CType('int')] int $set): int {}
#[Library('crypto'), Symbol('X509_REQ_sign'), CType('int')]
function reqSign(int $req, int $pkey, int $md): int {}
#[Library('crypto'), Symbol('PEM_write_bio_X509_REQ'), CType('int')]
function pemWriteReq(int $bio, int $req): int {}
#[Library('crypto'), Symbol('PEM_read_bio_X509_REQ')]
function pemReadReq(int $bio, Ptr $x, Ptr $cb, Ptr $u): int {}
#[Library('crypto'), Symbol('X509_REQ_get_pubkey')]
function reqGetPubkey(int $req): int {}
#[Library('crypto'), Symbol('X509_REQ_verify'), CType('int')]
function reqVerify(int $req, int $pkey): int {}
#[Library('crypto'), Symbol('X509_REQ_print'), CType('int')]
function reqPrint(int $bio, int $req): int {}

#[Library('crypto'), Symbol('X509_new')]
function x509New(): int {}
#[Library('crypto'), Symbol('X509_set_version'), CType('int')]
function x509SetVersion(int $x, #[CType('long')] int $version): int {}
#[Library('crypto'), Symbol('ASN1_INTEGER_set'), CType('int')]
function asn1IntegerSet(int $ai, #[CType('long')] int $v): int {}
#[Library('crypto'), Symbol('X509_set_issuer_name'), CType('int')]
function x509SetIssuerName(int $x, int $name): int {}
#[Library('crypto'), Symbol('X509_set_subject_name'), CType('int')]
function x509SetSubjectName(int $x, int $name): int {}
#[Library('crypto'), Symbol('X509_getm_notBefore')]
function x509MNotBefore(int $x): int {}
#[Library('crypto'), Symbol('X509_getm_notAfter')]
function x509MNotAfter(int $x): int {}
#[Library('crypto'), Symbol('X509_gmtime_adj')]
function x509GmtimeAdj(int $t, #[CType('long')] int $adj): int {}
#[Library('crypto'), Symbol('X509_set_pubkey'), CType('int')]
function x509SetPubkey(int $x, int $pkey): int {}
#[Library('crypto'), Symbol('X509_sign'), CType('int')]
function x509Sign(int $x, int $pkey, int $md): int {}

// NETSCAPE_SPKI
#[Library('crypto'), Symbol('NETSCAPE_SPKI_new')]
function spkiNew(): int {}
#[Library('crypto'), Symbol('NETSCAPE_SPKI_free')]
function spkiFree(int $s): void {}
#[Library('crypto'), Symbol('NETSCAPE_SPKI_set_pubkey'), CType('int')]
function spkiSetPubkey(int $s, int $pkey): int {}
#[Library('crypto'), Symbol('NETSCAPE_SPKI_sign'), CType('int')]
function spkiSign(int $s, int $pkey, int $md): int {}
#[Library('crypto'), Symbol('NETSCAPE_SPKI_b64_encode')]
function spkiB64Encode(int $s): Ptr {}
#[Library('crypto'), Symbol('NETSCAPE_SPKI_b64_decode')]
function spkiB64Decode(string $s, #[CType('int')] int $len): int {}
#[Library('crypto'), Symbol('NETSCAPE_SPKI_get_pubkey')]
function spkiGetPubkey(int $s): int {}
#[Library('crypto'), Symbol('NETSCAPE_SPKI_verify'), CType('int')]
function spkiVerify(int $s, int $pkey): int {}
#[Library('crypto'), Symbol('ASN1_STRING_set'), CType('int')]
function asn1StringSet(int $s, string $data, #[CType('int')] int $len): int {}
#[Library('crypto'), Symbol('ASN1_STRING_length'), CType('int')]
function asn1StringLength(int $s): int {}

// EC curves, default locations
#[Library('crypto'), Symbol('EC_get_builtin_curves')]
function ecBuiltinCurves(Ptr $r, #[CType('size_t')] int $n): int {}
#[Library('crypto'), Symbol('X509_get_default_cert_file')]
function defCertFile(): Ptr {}
#[Library('crypto'), Symbol('X509_get_default_cert_file_env')]
function defCertFileEnv(): Ptr {}
#[Library('crypto'), Symbol('X509_get_default_cert_dir')]
function defCertDir(): Ptr {}
#[Library('crypto'), Symbol('X509_get_default_cert_dir_env')]
function defCertDirEnv(): Ptr {}
#[Library('crypto'), Symbol('X509_get_default_private_dir')]
function defPrivateDir(): Ptr {}
#[Library('crypto'), Symbol('X509_get_default_cert_area')]
function defCertArea(): Ptr {}

// PKCS#12
#[Library('crypto'), Symbol('PKCS12_create')]
function p12Create(string $pass, string $name, int $pkey, int $cert, int $ca, #[CType('int')] int $nidKey,
                   #[CType('int')] int $nidCert, #[CType('int')] int $iter, #[CType('int')] int $macIter,
                   #[CType('int')] int $keytype): int {}
#[Library('crypto'), Symbol('PKCS12_create')]
function p12CreateNoName(string $pass, Ptr $name, int $pkey, int $cert, int $ca, #[CType('int')] int $nidKey,
                         #[CType('int')] int $nidCert, #[CType('int')] int $iter, #[CType('int')] int $macIter,
                         #[CType('int')] int $keytype): int {}
#[Library('crypto'), Symbol('PKCS12_free')]
function p12Free(int $p12): void {}
#[Library('crypto'), Symbol('i2d_PKCS12_bio'), CType('int')]
function i2dP12Bio(int $bio, int $p12): int {}
#[Library('crypto'), Symbol('d2i_PKCS12_bio')]
function d2iP12Bio(int $bio, Ptr $p12): int {}
#[Library('crypto'), Symbol('PKCS12_parse'), CType('int')]
function p12Parse(int $p12, string $pass, Ptr $ppkey, Ptr $pcert, Ptr $pca): int {}

// PKCS#7 / S-MIME
#[Library('crypto'), Symbol('PKCS7_sign')]
function p7Sign(int $cert, int $pkey, int $certs, int $in, #[CType('int')] int $flags): int {}
#[Library('crypto'), Symbol('PKCS7_free')]
function p7Free(int $p7): void {}
#[Library('crypto'), Symbol('SMIME_write_PKCS7'), CType('int')]
function smimeWrite(int $out, int $p7, int $data, #[CType('int')] int $flags): int {}
#[Library('crypto'), Symbol('SMIME_read_PKCS7')]
function smimeRead(int $in, Ptr $bcont): int {}
#[Library('crypto'), Symbol('PKCS7_verify'), CType('int')]
function p7Verify(int $p7, int $certs, int $store, int $indata, int $out, #[CType('int')] int $flags): int {}
#[Library('crypto'), Symbol('PKCS7_get0_signers')]
function p7Signers(int $p7, int $certs, #[CType('int')] int $flags): int {}
#[Library('crypto'), Symbol('PKCS7_encrypt')]
function p7Encrypt(int $certs, int $in, int $cipher, #[CType('int')] int $flags): int {}
#[Library('crypto'), Symbol('PKCS7_decrypt'), CType('int')]
function p7Decrypt(int $p7, int $pkey, int $cert, int $data, #[CType('int')] int $flags): int {}

// ── CMS (RFC 5652) — 1.1.1 and 3.x ──
#[Library('crypto'), Symbol('CMS_sign')]
function cmsSign(int $cert, int $pkey, int $certs, int $in, #[CType('unsigned int')] int $flags): int {}
#[Library('crypto'), Symbol('CMS_verify'), CType('int')]
function cmsVerify(int $cms, int $certs, int $store, int $dcont, int $out, #[CType('unsigned int')] int $flags): int {}
#[Library('crypto'), Symbol('CMS_encrypt')]
function cmsEncrypt(int $certs, int $in, int $cipher, #[CType('unsigned int')] int $flags): int {}
#[Library('crypto'), Symbol('CMS_decrypt'), CType('int')]
function cmsDecrypt(int $cms, int $pkey, int $cert, int $dcont, int $out, #[CType('unsigned int')] int $flags): int {}
#[Library('crypto'), Symbol('CMS_get0_signers')]
function cmsSigners(int $cms): int {}
#[Library('crypto'), Symbol('CMS_ContentInfo_free')]
function cmsFree(int $cms): void {}
#[Library('crypto'), Symbol('SMIME_write_CMS'), CType('int')]
function smimeWriteCms(int $out, int $cms, int $data, #[CType('int')] int $flags): int {}
#[Library('crypto'), Symbol('SMIME_read_CMS')]
function smimeReadCms(int $in, Ptr $bcont): int {}
#[Library('crypto'), Symbol('PEM_write_bio_CMS'), CType('int')]
function pemWriteCms(int $out, int $cms): int {}
#[Library('crypto'), Symbol('PEM_read_bio_CMS')]
function pemReadCms(int $in, Ptr $x, Ptr $cb, Ptr $u): int {}
#[Library('crypto'), Symbol('i2d_CMS_bio'), CType('int')]
function i2dCmsBio(int $out, int $cms): int {}
#[Library('crypto'), Symbol('d2i_CMS_bio')]
function d2iCmsBio(int $in, Ptr $cms): int {}
