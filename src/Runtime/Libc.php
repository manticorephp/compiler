<?php

namespace Runtime\Libc;

use Ffi\CType;
use Ffi\Give;
use Ffi\Library;
use Ffi\Ptr;
use Ffi\Symbol;
use Ffi\Take;
use Ffi\Variadic;

// ── Memory ─────────────────────────────────────────────────────────────

#[Library('c'), Symbol('malloc'), Give]
function malloc(#[CType('size_t')] int $size): Ptr {}

#[Library('c'), Symbol('calloc'), Give]
function calloc(#[CType('size_t')] int $n, #[CType('size_t')] int $size): Ptr {}

#[Library('c'), Symbol('realloc'), Give]
function realloc(#[Take] Ptr $p, #[CType('size_t')] int $size): Ptr {}

#[Library('c'), Symbol('free')]
function free(#[Take] Ptr $p): void {}

#[Library('c'), Symbol('memcpy')]
function memcpy(Ptr $dst, Ptr $src, #[CType('size_t')] int $n): Ptr {}

#[Library('c'), Symbol('memset')]
function memset(Ptr $dst, #[CType('int')] int $byte, #[CType('size_t')] int $n): Ptr {}

#[Library('c'), Symbol('memcmp')]
function memcmp(string $a, string $b, #[CType('size_t')] int $n): int {}

#[Library('c'), Symbol('memchr')]
function memchr(Ptr $hay, #[CType('int')] int $byte, #[CType('size_t')] int $n): Ptr {}

// ── Strings (NUL-terminated) ───────────────────────────────────────────

#[Library('c'), Symbol('strlen')]
function strlen(string $s): int {}

#[Library('c'), Symbol('strcmp')]
function strcmp(string $a, string $b): int {}

// `char *strerror(int errnum)` — the message for an errno. The returned buffer is
// libc's own (static/thread-local), so it is COPIED (cstr_to_str) and never freed.
#[Library('c'), Symbol('strerror')]
function strerror(#[CType('int')] int $errnum): Ptr {}

#[Library('c'), Symbol('strncmp')]
function strncmp(string $a, string $b, #[CType('size_t')] int $n): int {}

#[Library('c'), Symbol('strcasecmp')]
function strcasecmp(string $a, string $b): int {}

#[Library('c'), Symbol('strncasecmp')]
function strncasecmp(string $a, string $b, #[CType('size_t')] int $n): int {}

#[Library('c'), Symbol('strchr')]
function strchr(string $s, #[CType('int')] int $byte): Ptr {}

#[Library('c'), Symbol('strrchr')]
function strrchr(string $s, #[CType('int')] int $byte): Ptr {}

// `strstr` returns NULL when not found. We type the return as `int`
// (raw address) rather than Ptr so the PHP-side null check is a
// plain `=== 0` instead of needing a Ptr-object wrap that doesn't
// exist when libc hands us a null pointer.
#[Library('c'), Symbol('strstr')]
function strstr(string $hay, string $needle): int {}

#[Library('c'), Symbol('strdup'), Give]
function strdup(string $s): Ptr {}

#[Library('c'), Symbol('strncpy')]
function strncpy(Ptr $dst, string $src, #[CType('size_t')] int $n): Ptr {}

#[Library('c'), Symbol('strcpy')]
function strcpy(Ptr $dst, string $src): Ptr {}

#[Library('c'), Symbol('strcat')]
function strcat(Ptr $dst, string $src): Ptr {}

// ── stdio ──────────────────────────────────────────────────────────────

#[Library('c'), Symbol('puts')]
function puts(string $s): int {}

#[Library('c'), Symbol('write')]
function write(#[CType('int')] int $fd, string $buf, #[CType('size_t')] int $n): int {}

#[Library('c'), Symbol('read')]
function read(#[CType('int')] int $fd, Ptr $buf, #[CType('size_t')] int $n): int {}

// ── files / filesystem ─────────────────────────────────────────────────

#[Library('c'), Symbol('fopen'), Give]
function fopen(string $path, string $mode): Ptr {}

#[Library('c'), Symbol('fclose')]
function fclose(#[Take] Ptr $stream): int {}

// `FILE *popen(const char *command, const char *type)` — spawn a shell pipe.
// Owns a FILE* like fopen; MUST be closed with pclose (not fclose) so the child
// is reaped and its exit status returned.
#[Library('c'), Symbol('popen'), Give]
function popen(string $command, string $type): Ptr {}

#[Library('c'), Symbol('pclose')]
function pclose(#[Take] Ptr $stream): int {}

#[Library('c'), Symbol('fread')]
function fread(Ptr $buf, #[CType('size_t')] int $size, #[CType('size_t')] int $count, Ptr $stream): int {}

#[Library('c'), Symbol('fwrite')]
function fwrite(string $buf, #[CType('size_t')] int $size, #[CType('size_t')] int $count, Ptr $stream): int {}

// Same `fwrite` symbol as above, but taking a raw buffer instead of a headered
// string — for copying bytes straight from an fread buffer without a
// str_from_buffer round-trip through the heap.
#[Library('c'), Symbol('fwrite')]
function fwrite_buf(Ptr $buf, #[CType('size_t')] int $size, #[CType('size_t')] int $count, Ptr $stream): int {}

#[Library('c'), Symbol('fseek')]
function fseek(Ptr $stream, #[CType('long')] int $offset, #[CType('int')] int $whence): int {}

#[Library('c'), Symbol('ftell')]
function ftell(Ptr $stream): int {}

#[Library('c'), Symbol('feof')]
function feof(Ptr $stream): int {}

#[Library('c'), Symbol('fflush')]
function fflush(Ptr $stream): int {}

// `char *fgets(char *str, int n, FILE *stream)` — fills str, returns str (or
// NULL at EOF / on error). The result aliases the caller's $buf (NOT a fresh
// allocation, so no #[Give]); typed Ptr for the PHP-side EOF null-check.
#[Library('c'), Symbol('fgets')]
function fgets(Ptr $buf, #[CType('int')] int $n, Ptr $stream): Ptr {}

#[Library('c'), Symbol('access')]
function access(string $path, #[CType('int')] int $mode): int {}

// Directory / metadata syscalls. `sys_`-prefixed like sys_unlink/sys_getcwd —
// the plain name is taken by the php.net-facing wrapper in Stdlib/Io.php.
// mode_t/uid_t/gid_t are narrower than int on some hosts; the C ABI promotes
// them, so passing `int` is correct on both Darwin and Linux.

#[Library('c'), Symbol('mkdir')]
function sys_mkdir(string $path, #[CType('int')] int $mode): int {}

#[Library('c'), Symbol('rmdir')]
function sys_rmdir(string $path): int {}

#[Library('c'), Symbol('rename')]
function sys_rename(string $from, string $to): int {}

#[Library('c'), Symbol('chmod')]
function sys_chmod(string $path, #[CType('int')] int $mode): int {}

#[Library('c'), Symbol('chown')]
function sys_chown(string $path, #[CType('int')] int $owner, #[CType('int')] int $group): int {}

// `int lchown(const char *, uid_t, gid_t)` — chown a symlink ITSELF, not its
// target (the l* variant, like lstat vs stat).
#[Library('c'), Symbol('lchown')]
function sys_lchown(string $path, #[CType('int')] int $owner, #[CType('int')] int $group): int {}

#[Library('c'), Symbol('symlink')]
function sys_symlink(string $target, string $link): int {}

#[Library('c'), Symbol('link')]
function sys_link(string $target, string $link): int {}

// `ssize_t readlink(const char *, char *, size_t)` — does NOT NUL-terminate;
// the caller must cut the buffer to the returned length (str_from_buffer),
// never cstr_to_str.
#[Library('c'), Symbol('readlink')]
function sys_readlink(string $path, Ptr $buf, #[CType('size_t')] int $size): int {}

#[Library('c'), Symbol('truncate')]
function sys_truncate(string $path, #[CType('long')] int $length): int {}

#[Library('c'), Symbol('ftruncate')]
function sys_ftruncate(#[CType('int')] int $fd, #[CType('long')] int $length): int {}

#[Library('c'), Symbol('fileno')]
function sys_fileno(Ptr $stream): int {}

// `int isatty(int fd)` — 1 when fd is a terminal, 0 otherwise.
#[Library('c'), Symbol('isatty')]
function sys_isatty(#[CType('int')] int $fd): int {}

#[Library('c'), Symbol('flock')]
function sys_flock(#[CType('int')] int $fd, #[CType('int')] int $op): int {}

#[Library('c'), Symbol('fsync')]
function sys_fsync(#[CType('int')] int $fd): int {}

#[Library('c'), Symbol('umask')]
function sys_umask(#[CType('int')] int $mask): int {}

// `char *realpath(const char *, char *)` — called with a caller-owned
// PATH_MAX buffer (never NULL), so the result aliases $resolved rather than
// being a fresh allocation: no #[Give], and the caller frees the buffer.
// A NULL second arg would malloc instead, but `\Ffi\Ptr::null()` is not
// callable from here — Ptr's methods live in src/Ffi, which is outside the
// src/Runtime tree the stdlib library is built from.
#[Library('c'), Symbol('realpath')]
function sys_realpath(string $path, Ptr $resolved): Ptr {}

// `int utimes(const char *, const struct timeval[2])` — always passed a real
// timeval pair (see the NULL note on sys_realpath).
#[Library('c'), Symbol('utimes')]
function sys_utimes(string $path, Ptr $times): int {}

// ── stat / dirent ──────────────────────────────────────────────────────
// The caller supplies an over-allocated buffer and reads members by offset
// ({@see Stdlib/Stat.php}) — `struct stat` and `struct dirent` have no stable
// layout across targets, so nothing here can name a field.
//
// Symbol caveat: on macOS/x86_64 the modern SDK exports these as
// `stat$INODE64` / `readdir$INODE64`. #[Symbol] is a compile-time constant, so
// that target would need its own binding; arm64 macOS and Linux use the plain
// names below.

#[Library('c'), Symbol('stat')]
function sys_stat(string $path, Ptr $buf): int {}

// `int statvfs(const char *, struct statvfs *)` — filesystem stats for
// disk_free_space / disk_total_space. The struct layout differs per host
// (Darwin's block counts are 32-bit, glibc's 64-bit), read at runtime offsets.
#[Library('c'), Symbol('statvfs')]
function sys_statvfs(string $path, Ptr $buf): int {}

#[Library('c'), Symbol('lstat')]
function sys_lstat(string $path, Ptr $buf): int {}

#[Library('c'), Symbol('fstat')]
function sys_fstat(#[CType('int')] int $fd, Ptr $buf): int {}

#[Library('c'), Symbol('opendir'), Give]
function sys_opendir(string $path): Ptr {}

// Returns a pointer into the DIR's own storage (NOT a fresh allocation, so no
// #[Give]); NULL at end-of-directory.
#[Library('c'), Symbol('readdir')]
function sys_readdir(Ptr $dir): Ptr {}

#[Library('c'), Symbol('closedir')]
function sys_closedir(#[Take] Ptr $dir): int {}

#[Library('c'), Symbol('rewinddir')]
function sys_rewinddir(Ptr $dir): void {}

// `uname(struct utsname*)` — fills the buffer; `sysname` ("Darwin"/"Linux")
// is the first member at offset 0, NUL-terminated. The caller passes a
// generously-sized zeroed buffer (utsname is ~1.3 KB on macOS).
#[Library('c'), Symbol('uname')]
function uname(Ptr $buf): int {}

#[Library('c'), Symbol('unlink')]
function sys_unlink(string $path): int {}

#[Library('c'), Symbol('chdir')]
function sys_chdir(string $path): int {}

// glob(3)/globfree(3) are deliberately NOT bound: glob_t, the flag values and
// even the return codes differ per libc, and musl has no GLOB_BRACE — so
// glob() is implemented over scandir + fnmatch instead ({@see Stdlib/Fs.php}),
// the same call php made in 8.3.

// `int fnmatch(const char *pattern, const char *string, int flags)` — 0 on a
// match, FNM_NOMATCH (1) otherwise. The flag VALUES are host-specific
// (FNM_PATHNAME and FNM_NOESCAPE are swapped between Darwin and glibc), but php
// exposes the host's own header values, so callers pass them straight through.
#[Library('c'), Symbol('fnmatch')]
function sys_fnmatch(string $pattern, string $string, #[CType('int')] int $flags): int {}

// `FILE *tmpfile(void)` — an unnamed temp file, removed when closed.
#[Library('c'), Symbol('tmpfile'), Give]
function sys_tmpfile(): Ptr {}

// `int mkstemp(char *template)` — mutates the template in place (the trailing
// XXXXXX become the chosen suffix) and returns an open fd, or -1.
#[Library('c'), Symbol('mkstemp')]
function sys_mkstemp(Ptr $template): int {}

#[Library('c'), Symbol('close')]
function sys_close(#[CType('int')] int $fd): int {}

#[Library('c'), Symbol('getcwd'), Give]
function sys_getcwd(Ptr $buf, #[CType('size_t')] int $size): Ptr {}

// ── Network ────────────────────────────────────────────────────────────
//
// Sockets are libc, so they live here rather than in their own file: this file's
// axis is one file per LINKED LIBRARY (`Runtime\Pcre` = libpcre2), and splitting
// by topic would cut the `-lc` unit, which has a single owner.
//
// The client path is deliberately designed to need almost NO layout knowledge:
// `getaddrinfo` hands back a ready `ai_addr` + `ai_addrlen`, which go straight to
// `connect()` as an opaque blob — so no sockaddr is ever built or parsed here,
// and Darwin's `sin_len`/`sin_family` shift (and AF_INET6's 30-vs-10) never come
// up. `struct addrinfo` itself is the one exception: its result list has to be
// walked, and Darwin orders `ai_canonname` BEFORE `ai_addr` while glibc does the
// reverse. That table lives in Stdlib/Net.php, measured per host.
//
// Timeouts go through `poll()` rather than SO_RCVTIMEO: it takes an int
// MILLISECONDS argument and no struct, which sidesteps SOL_SOCKET (65535 vs 1),
// SO_RCVTIMEO (0x1006 vs 20) and `timeval.tv_usec` (4 vs 8 bytes) in one move.
// `struct pollfd` is identical on both hosts.
//
// NOTE there is no errno binding, on purpose: Darwin exports `__error()` and
// glibc `__errno_location()`, so binding both would leave an undefined symbol on
// whichever host lacks the other. Connect status comes from getsockopt(SO_ERROR);
// everything else reads a return code. Same family as the open `stat` vs
// `__xstat` gap on glibc < 2.33.

// `int getaddrinfo(const char *node, const char *service, const struct addrinfo
// *hints, struct addrinfo **res)` — 0 on success, else a gai error code (NOT
// errno). $hints may be a null Ptr (int_to_ptr(0)); $res is a Ptr to an 8-byte
// out slot that receives the head of the result list.
#[Library('c'), Symbol('getaddrinfo')]
function sys_getaddrinfo(string $node, string $service, Ptr $hints, Ptr $res): int {}

// `void freeaddrinfo(struct addrinfo *res)` — frees the whole list.
#[Library('c'), Symbol('freeaddrinfo')]
function sys_freeaddrinfo(#[Take] Ptr $res): void {}

// `const char *gai_strerror(int errcode)` — a getaddrinfo code is not errno, so
// this is the only way to name the failure.
#[Library('c'), Symbol('gai_strerror')]
function sys_gai_strerror(#[CType('int')] int $errcode): Ptr {}

// `int socket(int domain, int type, int protocol)` — fd, or -1. The three args
// come from an addrinfo entry, never from a constant we chose.
#[Library('c'), Symbol('socket')]
function sys_socket(#[CType('int')] int $domain, #[CType('int')] int $type,
                    #[CType('int')] int $protocol): int {}

// `int connect(int fd, const struct sockaddr *addr, socklen_t len)` — 0, or -1.
// $addr/$len are ai_addr/ai_addrlen, passed through untouched.
#[Library('c'), Symbol('connect')]
function sys_connect(#[CType('int')] int $fd, Ptr $addr, #[CType('int')] int $len): int {}

// `ssize_t send(int fd, const void *buf, size_t n, int flags)` — bytes sent, or -1.
#[Library('c'), Symbol('send')]
function sys_send(#[CType('int')] int $fd, string $buf, #[CType('size_t')] int $n,
                  #[CType('int')] int $flags): int {}

// Same call, raw buffer — mirrors the fwrite/fwrite_buf split: a PHP string is
// not a valid source for arbitrary bytes read back out of a socket.
#[Library('c'), Symbol('send')]
function sys_send_buf(#[CType('int')] int $fd, Ptr $buf, #[CType('size_t')] int $n,
                      #[CType('int')] int $flags): int {}

// `ssize_t recv(int fd, void *buf, size_t n, int flags)` — bytes read, 0 at the
// peer's orderly shutdown, -1 on error.
#[Library('c'), Symbol('recv')]
function sys_recv(#[CType('int')] int $fd, Ptr $buf, #[CType('size_t')] int $n,
                  #[CType('int')] int $flags): int {}

// `ssize_t recvfrom(int fd, void *buf, size_t n, int flags, sockaddr *src,
//  socklen_t *addrlen)` — like recv, but also fills the sender's address (for a
// datagram socket). $src/$addrlen may be NULL to ignore it.
#[Library('c'), Symbol('recvfrom')]
function sys_recvfrom(#[CType('int')] int $fd, Ptr $buf, #[CType('size_t')] int $n,
                      #[CType('int')] int $flags, Ptr $src, Ptr $addrlen): int {}

// `ssize_t sendto(int fd, const void *buf, size_t n, int flags,
//  const sockaddr *dst, socklen_t addrlen)` — send a datagram to a specific peer.
#[Library('c'), Symbol('sendto')]
function sys_sendto(#[CType('int')] int $fd, string $buf, #[CType('size_t')] int $n,
                    #[CType('int')] int $flags, Ptr $dst, #[CType('int')] int $addrlen): int {}

// `int getpeername(int fd, sockaddr *addr, socklen_t *addrlen)` — the address of
// the connected peer (the mirror of getsockname).
#[Library('c'), Symbol('getpeername')]
function sys_getpeername(#[CType('int')] int $fd, Ptr $addr, Ptr $addrlen): int {}

// `int poll(struct pollfd *fds, nfds_t nfds, int timeout)` — ready count, 0 on
// timeout, -1 on error. $timeout is MILLISECONDS; -1 blocks. This is the whole
// timeout mechanism (see the note above).
#[Library('c'), Symbol('poll')]
function sys_poll(Ptr $fds, #[CType('size_t')] int $nfds, #[CType('int')] int $timeout): int {}

// `int shutdown(int fd, int how)` — SHUT_RD/WR/RDWR are 0/1/2 on both hosts.
#[Library('c'), Symbol('shutdown')]
function sys_shutdown(#[CType('int')] int $fd, #[CType('int')] int $how): int {}

// `int getsockopt(int fd, int level, int optname, void *optval, socklen_t *optlen)`
// — the errno-free way to read a failed connect's cause, via SO_ERROR.
#[Library('c'), Symbol('getsockopt')]
function sys_getsockopt(#[CType('int')] int $fd, #[CType('int')] int $level,
                        #[CType('int')] int $optname, Ptr $optval, Ptr $optlen): int {}

// `int setsockopt(int fd, int level, int optname, const void *optval, socklen_t optlen)`
#[Library('c'), Symbol('setsockopt')]
function sys_setsockopt(#[CType('int')] int $fd, #[CType('int')] int $level,
                        #[CType('int')] int $optname, Ptr $optval,
                        #[CType('int')] int $optlen): int {}

// `int bind(int fd, const struct sockaddr *addr, socklen_t len)` — 0, or -1.
// $addr comes from getaddrinfo(AI_PASSIVE), so the server path builds no
// sockaddr either.
#[Library('c'), Symbol('bind')]
function sys_bind(#[CType('int')] int $fd, Ptr $addr, #[CType('int')] int $len): int {}

// `int listen(int fd, int backlog)` — 0, or -1.
#[Library('c'), Symbol('listen')]
function sys_listen(#[CType('int')] int $fd, #[CType('int')] int $backlog): int {}

// `int accept(int fd, struct sockaddr *addr, socklen_t *addrlen)` — a NEW fd for
// the connection, or -1. Both out-params may be NULL when the peer's address is
// of no interest, which keeps this off the sockaddr-parsing path entirely.
#[Library('c'), Symbol('accept')]
function sys_accept(#[CType('int')] int $fd, Ptr $addr, Ptr $addrlen): int {}

// `int getsockname(int fd, struct sockaddr *addr, socklen_t *addrlen)` — the
// address actually bound. The only reason it is here: binding to port 0 lets the
// OS choose a free one, and this is how we learn which. `sin_port` sits at
// offset 2 on EVERY probed target — Darwin splits the first two bytes as
// sin_len(u8)+sin_family(u8) and Linux as sin_family(u16), so the port lands in
// the same place either way, and reading it needs no host branch. It is in
// network byte order.
#[Library('c'), Symbol('getsockname')]
function sys_getsockname(#[CType('int')] int $fd, Ptr $addr, Ptr $addrlen): int {}

// `int gethostname(char *name, size_t len)` — the local host's name into $name.
#[Library('c'), Symbol('gethostname')]
function sys_gethostname(Ptr $name, #[CType('size_t')] int $len): int {}

// `int getnameinfo(const sockaddr *addr, socklen_t addrlen, char *host,
//  socklen_t hostlen, char *serv, socklen_t servlen, int flags)` — the reverse of
// getaddrinfo. With NI_NUMERICHOST it stringifies a sockaddr to its numeric IP
// without ANY sockaddr parsing on our side (getnameinfo owns the family details).
#[Library('c'), Symbol('getnameinfo')]
function sys_getnameinfo(Ptr $addr, #[CType('int')] int $addrlen, Ptr $host,
                        #[CType('int')] int $hostlen, Ptr $serv,
                        #[CType('int')] int $servlen, #[CType('int')] int $flags): int {}

// `int inet_pton(int af, const char *src, void *dst)` — a printable address to its
// packed bytes (4 for AF_INET, 16 for AF_INET6). 1 on success, 0 on a bad address.
#[Library('c'), Symbol('inet_pton')]
function sys_inet_pton(#[CType('int')] int $af, string $src, Ptr $dst): int {}

// service/protocol DB lookups → struct servent* / protoent* (LP64 layout, same on
// both hosts): servent { s_name@0, s_aliases@8, s_port@16 (net order), s_proto@24 };
// protoent { p_name@0, p_aliases@8, p_proto@16 }. NULL when not found.
#[Library('c'), Symbol('getservbyname')]
function sys_getservbyname(string $name, string $proto): Ptr {}
#[Library('c'), Symbol('getservbyport')]
function sys_getservbyport(#[CType('int')] int $port, string $proto): Ptr {}
#[Library('c'), Symbol('getprotobyname')]
function sys_getprotobyname(string $name): Ptr {}
#[Library('c'), Symbol('getprotobynumber')]
function sys_getprotobynumber(#[CType('int')] int $proto): Ptr {}

// `void openlog(const char *ident, int option, int facility)` /
// `void syslog(int priority, const char *message)` / `void closelog(void)`.
#[Library('c'), Symbol('openlog')]
function sys_openlog(string $ident, #[CType('int')] int $option, #[CType('int')] int $facility): void {}
#[Library('c'), Symbol('syslog')]
function sys_syslog(#[CType('int')] int $priority, string $message): void {}
#[Library('c'), Symbol('closelog')]
function sys_closelog(): void {}

// `const char *inet_ntop(int af, const void *src, char *dst, socklen_t size)` — the
// reverse: packed bytes to a printable string in $dst (NULL on failure).
#[Library('c'), Symbol('inet_ntop')]
function sys_inet_ntop(#[CType('int')] int $af, Ptr $src, Ptr $dst, #[CType('int')] int $size): Ptr {}

// ── ext/sockets extras ─────────────────────────────────────────────────
// The socket_* surface (Stdlib/Sockets.php) rides the syscalls already bound
// above; these two are the only additions it needs.

// `int fcntl(int fd, int cmd, ... /* int arg */)` — read/set the fd flags for
// socket_set_block / socket_set_nonblock (F_GETFL then F_SETFL with O_NONBLOCK
// toggled). fcntl is VARIADIC (the third arg is the vararg): #[Variadic(2)]
// marks fd+cmd as the two NAMED params so the wrapper emits a variadic call —
// without it the arg is passed in a register and Darwin arm64's variadic ABI
// (varargs on the stack) reads garbage where the callee does va_arg.
#[Library('c'), Symbol('fcntl'), Variadic(2)]
function sys_fcntl(#[CType('int')] int $fd, #[CType('int')] int $cmd, #[CType('int')] int $arg): int {}

// `int socketpair(int domain, int type, int protocol, int sv[2])` — a connected
// pair of fds for socket_create_pair. $sv is an 8-byte out buffer receiving two
// int fds (peek_i32 @0 and @4).
#[Library('c'), Symbol('socketpair')]
function sys_socketpair(#[CType('int')] int $domain, #[CType('int')] int $type,
                        #[CType('int')] int $protocol, Ptr $sv): int {}

// `int sockatmark(int fd)` — 1 if the read pointer is at the OOB mark, 0 if not,
// -1 on error. Backs socket_atmark.
#[Library('c'), Symbol('sockatmark')]
function sys_sockatmark(#[CType('int')] int $fd): int {}

// `int dup(int fd)` — a NEW fd referring to the same open file/socket, or -1.
// Used to duplicate a fd for socket_import_stream / socket_export_stream. dup(2)
// is non-variadic, unlike fcntl(F_DUPFD) whose vararg arg breaks the Darwin arm64
// variadic ABI when called through the fixed-arity FFI wrapper.
#[Library('c'), Symbol('dup')]
function sys_dup(#[CType('int')] int $fd): int {}

// ── Randomness ─────────────────────────────────────────────────────────
// `int getentropy(void *buf, size_t buflen)` — fill $buf with $buflen (<= 256)
// cryptographically-secure random bytes; 0 on success, -1 on error. Present on
// both Darwin and glibc (>= 2.25), non-variadic — the cross-host choice for
// random_bytes/random_int (getrandom is Linux-only, arc4random_buf BSD-only).
#[Library('c'), Symbol('getentropy')]
function sys_getentropy(Ptr $buf, #[CType('size_t')] int $buflen): int {}
