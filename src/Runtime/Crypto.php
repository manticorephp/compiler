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
