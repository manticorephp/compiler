<?php

namespace Runtime\Openssl;

use Ffi\CType;
use Ffi\Library;
use Ffi\Ptr;
use Ffi\Symbol;

// Thin FFI binding to the host OpenSSL 3.x (libssl + libcrypto). This is the
// TLS transport under `https://` — the scheme seam in Io.php swaps it in for the
// plain socket, and the HTTP layer above (__mc_http_read_response) is unchanged.
//
// Opaque handles (SSL*, SSL_CTX*, const SSL_METHOD*) are carried as raw i64
// addresses (`int`) so a NULL is a plain `=== 0` check — the same convention the
// PCRE2 binding uses. We never poke inside these structs; OpenSSL 3.x keeps them
// all opaque, so no offset table is needed (unlike the addrinfo probe Ф2 owed).
//
// A C `int` return (SSL_connect/SSL_read/SSL_write: 1 = ok, <=0 = error) has
// undefined upper 32 bits when declared i64 — callers compare `> 0` / `=== 1`,
// never a raw i64.
//
// No SSL_library_init(): OpenSSL 1.1+/3.x self-initialise on first use.

// ── context ──
// SSL_CTX *SSL_CTX_new(const SSL_METHOD *method)
#[Library('ssl'), Symbol('SSL_CTX_new')]
function ctxNew(int $method): int {}

// const SSL_METHOD *TLS_client_method(void) — negotiates the highest TLS the peer
// supports; the versioned methods (TLSv1_2_client_method, …) are deprecated.
#[Library('ssl'), Symbol('TLS_client_method')]
function clientMethod(): int {}

// const SSL_METHOD *TLS_server_method(void) — the server side of the above, for a
// TLS listener that accepts and handshakes incoming connections.
#[Library('ssl'), Symbol('TLS_server_method')]
function serverMethod(): int {}

// int SSL_CTX_use_certificate_chain_file(SSL_CTX *ctx, const char *file) — load the
// server's leaf cert (plus any intermediates) from a PEM file, 1 on success.
#[Library('ssl'), Symbol('SSL_CTX_use_certificate_chain_file')]
function ctxUseCertChainFile(int $ctx, string $file): int {}

// int SSL_CTX_use_PrivateKey_file(SSL_CTX *ctx, const char *file, int type) — load
// the matching private key; type SSL_FILETYPE_PEM (1). 1 on success.
#[Library('ssl'), Symbol('SSL_CTX_use_PrivateKey_file')]
function ctxUsePrivateKeyFile(int $ctx, string $file, #[CType('int')] int $type): int {}

// int SSL_accept(SSL *ssl) — the server-side handshake on an accepted fd, 1 on
// success, <=0 on error.
#[Library('ssl'), Symbol('SSL_accept')]
function accept(int $ssl): int {}

// void SSL_CTX_free(SSL_CTX *ctx) — SSL_new takes a ref on the ctx, so the ctx may
// be freed right after and lives until the last SSL is freed.
#[Library('ssl'), Symbol('SSL_CTX_free')]
function ctxFree(int $ctx): void {}

// void SSL_CTX_set_verify(SSL_CTX *ctx, int mode, void *cb) — cb NULL uses the
// built-in verifier. mode SSL_VERIFY_PEER (1) makes SSL_connect fail a bad chain.
#[Library('ssl'), Symbol('SSL_CTX_set_verify')]
function ctxSetVerify(int $ctx, #[CType('int')] int $mode, Ptr $cb): void {}

// int SSL_CTX_set_default_verify_paths(SSL_CTX *ctx) — load the host trust store
// (OPENSSLDIR/cert.pem etc.), 1 on success.
#[Library('ssl'), Symbol('SSL_CTX_set_default_verify_paths')]
function ctxSetDefaultVerifyPaths(int $ctx): int {}

// ── connection ──
// SSL *SSL_new(SSL_CTX *ctx)
#[Library('ssl'), Symbol('SSL_new')]
function sslNew(int $ctx): int {}

// int SSL_set_fd(SSL *ssl, int fd) — bind the TLS engine to an already-connected
// TCP fd, 1 on success.
#[Library('ssl'), Symbol('SSL_set_fd')]
function setFd(int $ssl, #[CType('int')] int $fd): int {}

// long SSL_ctrl(SSL *ssl, int cmd, long larg, void *parg) — the real call behind
// the SSL_set_tlsext_host_name() macro: cmd SSL_CTRL_SET_TLSEXT_HOSTNAME (55),
// larg TLSEXT_NAMETYPE_host_name (0), parg the SNI host string.
#[Library('ssl'), Symbol('SSL_ctrl')]
function ctrl(int $ssl, #[CType('int')] int $cmd, #[CType('long')] int $larg, string $parg): int {}

// int SSL_set1_host(SSL *s, const char *hostname) — the hostname the peer cert
// must match; without it a valid chain for the WRONG host would pass. 1 on success.
#[Library('ssl'), Symbol('SSL_set1_host')]
function set1Host(int $ssl, string $hostname): int {}

// int SSL_connect(SSL *ssl) — the TLS handshake, 1 on success, <=0 on error.
#[Library('ssl'), Symbol('SSL_connect')]
function connect(int $ssl): int {}

// int SSL_read(SSL *ssl, void *buf, int num) — bytes read, <=0 on close/error.
#[Library('ssl'), Symbol('SSL_read')]
function read(int $ssl, Ptr $buf, #[CType('int')] int $num): int {}

// int SSL_write(SSL *ssl, const void *buf, int num) — bytes written, <=0 on error.
#[Library('ssl'), Symbol('SSL_write')]
function write(int $ssl, string $buf, #[CType('int')] int $num): int {}

// int SSL_get_error(const SSL *ssl, int ret) — classify a <=0 return.
#[Library('ssl'), Symbol('SSL_get_error')]
function getError(int $ssl, #[CType('int')] int $ret): int {}

// int SSL_pending(const SSL *ssl) — decrypted bytes already buffered in the SSL
// engine. When >0, SSL_read returns them WITHOUT the socket fd being readable, so
// a read-timeout must not poll the fd first (it would stall on data we already hold).
#[Library('ssl'), Symbol('SSL_pending')]
function pending(int $ssl): int {}

// int SSL_shutdown(SSL *ssl) — send close_notify. One call is enough for our
// short-lived request; we do not wait for the peer's reply.
#[Library('ssl'), Symbol('SSL_shutdown')]
function shutdown(int $ssl): int {}

// void SSL_free(SSL *ssl) — also drops the ctx ref and the BIO, but NOT the fd
// (SSL_set_fd does not take ownership), so the fd is closed separately.
#[Library('ssl'), Symbol('SSL_free')]
function sslFree(int $ssl): void {}
