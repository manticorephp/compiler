<?php

/**
 * pdo_sql — code shared by the socket drivers (pdo_pgsql, pdo_mysql): the
 * statement-text rewriting and wire helpers neither driver should own twice.
 * Concatenated AFTER pdo.php and BEFORE the drivers that use it.
 */

/**
 * What {@see __McPdoSql::scan} found. A class, not an array: `sql` is text while
 * `names` / `pos` are lists, and a mixed-value array loses its element channel.
 */
final class __McPdoSqlScan
{
    /** pgsql: the statement with every placeholder rewritten to `$n`; mysql: the input, untouched. */
    public string $sql = '';

    /**
     * One entry per bound slot, in statement order: `'?'` or `':name'`. pgsql
     * numbers a repeated `:name` once (slot = first occurrence); mysql
     * emulation gives every occurrence its own slot.
     * @var array<int,string>
     */
    public array $names = [];

    /**
     * mysql only: byte offset in `sql` of slot i's placeholder
     * (`strlen($names[$i])` bytes long), for splicing in quoted values.
     * @var array<int,int>
     */
    public array $pos = [];
}

final class __McPdoSql
{
    /**
     * The placeholder walk php's PDO SQL parser does, one forward pass.
     * `$dialect` is `'pgsql'` or `'mysql'`; everything below is what the live
     * oracle (php 8.5 pdo_pgsql / pdo_mysql) does, not what the SQL grammar says:
     *  - a quote with no closing partner is a plain character and scanning goes on;
     *    an unterminated `/*` or `$tag$` swallows the rest of the text
     *  - `/* *` / is NOT nested, in either dialect
     *  - `:name` needs `[A-Za-z0-9_]+` and a previous byte that is neither a name
     *    byte nor `:` (so `a:b`, `:a:b`, `::int` bind nothing)
     *  - pgsql: each `??` pair is a literal `?` (an odd leftover is a slot); mysql
     *    keeps a run of two or more `?` as written, no slot
     */
    public static function scan(string $sql, string $dialect): __McPdoSqlScan
    {
        $pg = $dialect === 'pgsql';
        $n = \strlen($sql);
        $r = new __McPdoSqlScan();
        $out = '';
        /** @var array<string,int> */
        $seen = [];
        $slots = 0;
        $named = false;
        $positional = false;
        $run = 0;
        $i = 0;
        while ($i < $n) {
            $c = $sql[$i];
            if ($c === "'") {
                $esc = !$pg || ($i > 0 && ($sql[$i - 1] === 'e' || $sql[$i - 1] === 'E'));
                $e = self::skipQuoted($sql, $i, "'", $esc);
                $i = $e < 0 ? $i + 1 : $e;
            } elseif ($c === '"') {
                $e = self::skipQuoted($sql, $i, '"', !$pg);
                $i = $e < 0 ? $i + 1 : $e;
            } elseif ($c === '`' && !$pg) {
                $e = self::skipQuoted($sql, $i, '`', false);
                $i = $e < 0 ? $i + 1 : $e;
            } elseif ($c === '$' && $pg) {
                $j = $i + 1;
                if ($j < $n && self::isNameByte(\ord($sql[$j]), false)) {
                    while ($j < $n && self::isNameByte(\ord($sql[$j]), true)) { $j++; }
                }
                if ($j < $n && $sql[$j] === '$') {
                    $tag = \substr($sql, $i, $j - $i + 1);
                    $e = \strpos($sql, $tag, $j + 1);
                    $i = $e === false ? $n : $e + \strlen($tag);
                } else {
                    $i++;
                }
            } elseif ($c === '-' && $i + 1 < $n && $sql[$i + 1] === '-'
                      && ($pg || $i + 2 >= $n || \ord($sql[$i + 2]) <= 32)) {
                $e = \strpos($sql, "\n", $i);
                $i = $e === false ? $n : $e;
            } elseif ($c === '#' && !$pg) {
                $e = \strpos($sql, "\n", $i);
                $i = $e === false ? $n : $e;
            } elseif ($c === '/' && $i + 1 < $n && $sql[$i + 1] === '*') {
                $e = \strpos($sql, '*/', $i + 2);
                $i = $e === false ? $n : $e + 2;
            } elseif ($c === '?') {
                if ($i + 1 < $n && $sql[$i + 1] === '?') {
                    if ($pg) {
                        $out .= \substr($sql, $run, $i + 1 - $run);
                        $run = $i + 2;
                        $i += 2;
                    } else {
                        while ($i < $n && $sql[$i] === '?') { $i++; }
                    }
                    continue;
                }
                if ($named) { throw self::mixed(); }
                $positional = true;
                $r->names[] = '?';
                $slots++;
                if ($pg) {
                    $out .= \substr($sql, $run, $i - $run) . '$' . (string) $slots;
                    $run = $i + 1;
                } else {
                    $r->pos[] = $i;
                }
                $i++;
            } elseif ($c === ':') {
                if ($i + 1 < $n && $sql[$i + 1] === ':') {
                    $i += 2;
                    continue;
                }
                $j = $i + 1;
                while ($j < $n && self::isNameByte(\ord($sql[$j]), true) && \ord($sql[$j]) < 128) { $j++; }
                if ($j === $i + 1 || ($i > 0 && ($sql[$i - 1] === ':' || (self::isNameByte(\ord($sql[$i - 1]), true)
                                                                         && \ord($sql[$i - 1]) < 128)))) {
                    $i++;
                    continue;
                }
                if ($positional) { throw self::mixed(); }
                $named = true;
                $name = \substr($sql, $i, $j - $i);
                if ($pg) {
                    if (isset($seen[$name])) {
                        $slot = $seen[$name];
                    } else {
                        $r->names[] = $name;
                        $slots++;
                        $slot = $slots;
                        $seen[$name] = $slot;
                    }
                    $out .= \substr($sql, $run, $i - $run) . '$' . (string) $slot;
                    $run = $j;
                } else {
                    $r->names[] = $name;
                    $r->pos[] = $i;
                }
                $i = $j;
            } else {
                $i++;
            }
        }
        $r->sql = $pg ? $out . \substr($sql, $run) : $sql;
        return $r;
    }

    /** The pgsql rewrite: `?` / `:name` → `$1..$n`, a repeated name reusing its number. */
    public static function rewritePg(string $sql): __McPdoSqlScan
    {
        return self::scan($sql, 'pgsql');
    }

    private static function mixed(): PDOException
    {
        return __McPdo::fail('HY093', 0, false, 'mixed named and positional parameters', true);
    }

    /** Name byte: `[A-Za-z0-9_]`; `$digits` false = a leading byte (no digit). High bytes count too. */
    private static function isNameByte(int $o, bool $digits): bool
    {
        return ($o >= 97 && $o <= 122) || ($o >= 65 && $o <= 90) || $o === 95 || $o >= 128
            || ($digits && $o >= 48 && $o <= 57);
    }

    /**
     * Index just past the closing `$q` of the literal opening at `$i`, or -1 when
     * it never closes. A doubled `$q` stays inside; `$bs` makes a backslash eat
     * the next byte.
     */
    private static function skipQuoted(string $sql, int $i, string $q, bool $bs): int
    {
        $n = \strlen($sql);
        $j = $i + 1;
        while ($j < $n) {
            $ch = $sql[$j];
            if ($bs && $ch === '\\') {
                $j += 2;
            } elseif ($ch === $q) {
                if ($j + 1 < $n && $sql[$j + 1] === $q) { $j += 2; continue; }
                return $j + 1;
            } else {
                $j++;
            }
        }
        return -1;
    }
}

// ── TLS for the socket drivers ──────────────────────────────────────────────
//
// The stdlib's stream_socket_enable_crypto() knows no CA file, client certificate, cipher list or CRL, and
// reports no reason on failure, so the drivers drive OpenSSL themselves over the same fd and then turn the
// resource into a KIND_TLS stream (fread/fwrite/fclose already speak SSL_read/SSL_write/SSL_free for that kind).
// Only symbols src/Runtime/Openssl.php does not bind are declared here (one signature per C symbol).

#[\Ffi\Library('ssl'), \Ffi\Symbol('SSL_CTX_load_verify_file'), \Ffi\CType('int')]
function __mc_pdotls_load_file(int $ctx, string $file): int {}

#[\Ffi\Library('ssl'), \Ffi\Symbol('SSL_CTX_load_verify_dir'), \Ffi\CType('int')]
function __mc_pdotls_load_dir(int $ctx, string $dir): int {}

// SSL_CTX_set_{min,max}_proto_version are macros over SSL_CTX_ctrl (SSL_CTRL_SET_MIN_PROTO_VERSION 123, _MAX_ 124)
#[\Ffi\Library('ssl'), \Ffi\Symbol('SSL_CTX_ctrl')]
function __mc_pdotls_ctx_ctrl(int $ctx, #[\Ffi\CType('int')] int $cmd, #[\Ffi\CType('long')] int $larg, \Ffi\Ptr $parg): int {}

#[\Ffi\Library('ssl'), \Ffi\Symbol('SSL_CTX_set_cipher_list'), \Ffi\CType('int')]
function __mc_pdotls_ciphers(int $ctx, string $list): int {}

#[\Ffi\Library('ssl'), \Ffi\Symbol('SSL_CTX_get_cert_store')]
function __mc_pdotls_store(int $ctx): int {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('X509_STORE_load_file'), \Ffi\CType('int')]
function __mc_pdotls_store_load(int $store, string $file): int {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('X509_STORE_set_flags'), \Ffi\CType('int')]
function __mc_pdotls_store_flags(int $store, #[\Ffi\CType('long')] int $flags): int {}

#[\Ffi\Library('ssl'), \Ffi\Symbol('SSL_get1_peer_certificate')]
function __mc_pdotls_peer(int $ssl): int {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('X509_check_host'), \Ffi\CType('int')]
function __mc_pdotls_check_host(int $x, string $name, #[\Ffi\CType('size_t')] int $len,
                                #[\Ffi\CType('int')] int $flags, \Ffi\Ptr $peername): int {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('X509_check_ip_asc'), \Ffi\CType('int')]
function __mc_pdotls_check_ip(int $x, string $ip, #[\Ffi\CType('int')] int $flags): int {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('X509_free')]
function __mc_pdotls_x509_free(int $x): void {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('X509_get_ext_d2i')]
function __mc_pdotls_ext(int $x, #[\Ffi\CType('int')] int $nid, \Ffi\Ptr $crit, \Ffi\Ptr $idx): int {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('OPENSSL_sk_num'), \Ffi\CType('int')]
function __mc_pdotls_sk_num(int $sk): int {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('OPENSSL_sk_value')]
function __mc_pdotls_sk_value(int $sk, #[\Ffi\CType('int')] int $i): int {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('GENERAL_NAMES_free')]
function __mc_pdotls_names_free(int $sk): void {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('ASN1_STRING_get0_data')]
function __mc_pdotls_asn1_data(int $s): \Ffi\Ptr {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('ASN1_STRING_length'), \Ffi\CType('int')]
function __mc_pdotls_asn1_len(int $s): int {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('X509_get_subject_name')]
function __mc_pdotls_subject(int $x): int {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('X509_NAME_get_text_by_NID'), \Ffi\CType('int')]
function __mc_pdotls_name_text(int $name, #[\Ffi\CType('int')] int $nid, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $len): int {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('ERR_get_error')]
function __mc_pdotls_err(): int {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('ERR_reason_error_string')]
function __mc_pdotls_err_str(int $code): \Ffi\Ptr {}

#[\Ffi\Library('crypto'), \Ffi\Symbol('ERR_clear_error')]
function __mc_pdotls_err_clear(): void {}

/** What the driver wants from the TLS layer. A class: its fields differ in type (an array would lose them). */
final class __McPdoTlsOpts
{
    /** The name (or IP) the certificate is checked against and sent as SNI. */
    public string $host = '';
    /** SSL_VERIFY_PEER: a chain that does not lead to a trusted root fails the handshake. */
    public bool $verifyChain = false;
    /** The leaf certificate must match $host. */
    public bool $verifyName = false;
    public string $caFile = '';
    public string $caDir = '';
    /** Trust the host's default store (sslrootcert=system, or php's default when verifying with no CA given). */
    public bool $systemRoots = false;
    public string $crl = '';
    public string $cert = '';
    /** '' = the key is in $cert's file. */
    public string $key = '';
    public string $ciphers = '';
    public bool $sni = true;
    /** Protocol floor / ceiling (TLS1_2_VERSION = 0x0303 ...); 0 = the library default for the ceiling. */
    public int $minVersion = 0x0303;
    public int $maxVersion = 0;
    /** pgsql: the names libpq prints in its protocol-version hint. */
    public string $minName = 'TLSv1.2';
    public string $maxName = 'TLSv1.3';
}

/** Outcome of {@see __McPdoTls::start}. `stage` names the step that failed; '' = success. */
final class __McPdoTlsRes
{
    public string $stage = '';
    /** OpenSSL's reason string for the failing step ('' = none queued: the peer simply went away). */
    public string $reason = '';
    /** stage 'name': the certificate's first name and how many more it carries. */
    public string $certName = '';
    public int $others = 0;
}

final class __McPdoTls
{
    private static function reason(): string
    {
        $code = __mc_pdotls_err();
        if ($code === 0) { return ''; }
        $p = __mc_pdotls_err_str($code);
        return \ptr_to_int($p) === 0 ? '' : \cstr_to_str($p);
    }

    private static function fail(__McPdoTlsRes $r, string $stage): __McPdoTlsRes
    {
        $r->stage = $stage;
        $r->reason = self::reason();
        __mc_pdotls_err_clear();
        return $r;
    }

    public static function isIp(string $h): bool
    {
        return \strpos($h, ':') !== false || \ip2long($h) !== false;
    }

    /**
     * Unread plaintext on a socket that is about to start TLS: bytes in the stream buffer, or arriving on the wire.
     * A TLS server says nothing until it hears the ClientHello, so anything here was injected (CVE-2021-23222:
     * plaintext that would otherwise be parsed as if it came from the TLS session).
     */
    public static function pendingPlain(\Resource $sock): bool
    {
        return \strlen($sock->rbuf) - $sock->rpos > 0 || \__mc_poll_readable($sock->addr, 0) > 0;
    }

    /** Client handshake over the already connected plain socket; on success $sock is a KIND_TLS stream. */
    public static function start(\Resource $sock, __McPdoTlsOpts $o, float $budget): __McPdoTlsRes
    {
        $res = new __McPdoTlsRes();
        __mc_pdotls_err_clear();
        $ctx = \Runtime\Openssl\ctxNew(\Runtime\Openssl\clientMethod());
        if ($ctx === 0) { return self::fail($res, 'init'); }
        if (__mc_pdotls_ctx_ctrl($ctx, 123, $o->minVersion, \int_to_ptr(0)) !== 1 || ($o->maxVersion !== 0 && __mc_pdotls_ctx_ctrl($ctx, 124, $o->maxVersion, \int_to_ptr(0)) !== 1)) {
            \Runtime\Openssl\ctxFree($ctx);
            return self::fail($res, 'init');
        }
        if ($o->ciphers !== '' && __mc_pdotls_ciphers($ctx, $o->ciphers) !== 1) {
            \Runtime\Openssl\ctxFree($ctx);
            return self::fail($res, 'cipher');
        }
        if ($o->cert !== '') {
            if (\Runtime\Openssl\ctxUseCertChainFile($ctx, $o->cert) !== 1) {
                \Runtime\Openssl\ctxFree($ctx);
                return self::fail($res, 'cert');
            }
            // 1 = SSL_FILETYPE_PEM; with the certificate loaded, OpenSSL also checks that the key belongs to it
            if (\Runtime\Openssl\ctxUsePrivateKeyFile($ctx, $o->key !== '' ? $o->key : $o->cert, 1) !== 1) {
                \Runtime\Openssl\ctxFree($ctx);
                return self::fail($res, 'key');
            }
        }
        if ($o->caFile !== '' && __mc_pdotls_load_file($ctx, $o->caFile) !== 1) {
            \Runtime\Openssl\ctxFree($ctx);
            return self::fail($res, 'root');
        }
        if ($o->caDir !== '' && __mc_pdotls_load_dir($ctx, $o->caDir) !== 1) {
            \Runtime\Openssl\ctxFree($ctx);
            return self::fail($res, 'root');
        }
        if ($o->systemRoots) { \Runtime\Openssl\ctxSetDefaultVerifyPaths($ctx); }
        if ($o->crl !== '') {
            $store = __mc_pdotls_store($ctx);
            // X509_V_FLAG_CRL_CHECK 0x4 | X509_V_FLAG_CRL_CHECK_ALL 0x8, as libpq
            if ($store === 0 || __mc_pdotls_store_load($store, $o->crl) !== 1 || __mc_pdotls_store_flags($store, 12) !== 1) {
                \Runtime\Openssl\ctxFree($ctx);
                return self::fail($res, 'crl');
            }
        }
        \Runtime\Openssl\ctxSetVerify($ctx, $o->verifyChain ? 1 : 0, \int_to_ptr(0));
        $ssl = \Runtime\Openssl\sslNew($ctx);
        \Runtime\Openssl\ctxFree($ctx);   // SSL_new holds its own reference
        if ($ssl === 0) { return self::fail($res, 'init'); }
        if ($o->sni && $o->host !== '' && !self::isIp($o->host)) {
            \Runtime\Openssl\ctrl($ssl, 55, 0, $o->host);   // SSL_CTRL_SET_TLSEXT_HOSTNAME
        }
        if (\Runtime\Openssl\setFd($ssl, $sock->addr) !== 1) {
            \Runtime\Openssl\sslFree($ssl);
            return self::fail($res, 'init');
        }
        // a cancellation raised while the handshake is parked must not leak the SSL object
        try {
            $rc = self::drive($sock, $ssl, $budget);
        } catch (\Throwable $t) {
            \Runtime\Openssl\sslFree($ssl);
            __mc_pdotls_err_clear();
            throw $t;
        }
        if ($rc !== 1) {
            $err = \Runtime\Openssl\getError($ssl, $rc);
            $r = self::fail($res, $err === 2 || $err === 3 ? 'timeout' : 'connect');
            \Runtime\Openssl\sslFree($ssl);
            return $r;
        }
        if ($o->verifyName && !self::nameMatches($ssl, $o->host, $res)) {
            \Runtime\Openssl\sslFree($ssl);
            $res->stage = 'name';
            return $res;
        }
        $sock->kind = \Resource::KIND_TLS;
        $sock->type = 'stream';
        $sock->ssl = $ssl;
        __mc_pdotls_err_clear();
        return $res;
    }

    /** SSL_connect to completion within $budget seconds; the fiber parks under a scheduler, the thread polls without one. */
    private static function drive(\Resource $sock, int $ssl, float $budget): int
    {
        if (\Runtime\AsyncHook::active()) { return \__mc_tls_drive_connect($sock, $ssl, $budget); }
        $deadline = \__mc_monotonic_f() + ($budget > 0.0 ? $budget : 60.0);
        \stream_set_blocking($sock, false);
        $rc = \Runtime\Openssl\connect($ssl);
        while ($rc !== 1) {
            $err = \Runtime\Openssl\getError($ssl, $rc);
            if ($err !== 2 && $err !== 3) { break; }
            $left = $deadline - \__mc_monotonic_f();
            if ($left <= 0.0) { break; }
            $ms = (int) ($left * 1000.0) + 1;
            if ($err === 2) {
                \__mc_poll_readable($sock->addr, $ms);
            } else {
                $r = [];
                $w = [$sock];
                $x = [];
                \stream_select($r, $w, $x, 0, $ms * 1000);
            }
            $rc = \Runtime\Openssl\connect($ssl);
        }
        \stream_set_blocking($sock, true);
        return $rc;
    }

    /** The peer certificate must carry $host (a DNS name or an IP); on a mismatch $res gets its first name and the count of the others. */
    private static function nameMatches(int $ssl, string $host, __McPdoTlsRes $res): bool
    {
        $x = __mc_pdotls_peer($ssl);
        if ($x === 0) { return false; }
        $ok = self::isIp($host)
            ? __mc_pdotls_check_ip($x, $host, 0) === 1
            : __mc_pdotls_check_host($x, $host, \strlen($host), 0, \int_to_ptr(0)) === 1;
        if (!$ok) {
            $names = [];
            // 85 = NID_subject_alt_name; GENERAL_NAME is {int type; union {ASN1_STRING *} d} (d at offset 8 on LP64)
            $sk = __mc_pdotls_ext($x, 85, \int_to_ptr(0), \int_to_ptr(0));
            if ($sk !== 0) {
                $n = __mc_pdotls_sk_num($sk);
                for ($i = 0; $i < $n; $i++) {
                    $gn = __mc_pdotls_sk_value($sk, $i);
                    $type = \peek_i32(\int_to_ptr($gn), 0);
                    if ($type !== 2 && $type !== 7) { continue; }   // GEN_DNS, GEN_IPADD
                    $s = \peek_i64(\int_to_ptr($gn), 8);
                    $len = __mc_pdotls_asn1_len($s);
                    $raw = $len > 0 && $len <= 255 ? \str_from_buffer(__mc_pdotls_asn1_data($s), $len) : '';
                    if ($type === 7 && $len === 4) { $raw = (string) \ord($raw[0]) . '.' . (string) \ord($raw[1]) . '.' . (string) \ord($raw[2]) . '.' . (string) \ord($raw[3]); }
                    elseif ($type === 7) { $inet = \inet_ntop($raw); $raw = $inet === false ? '' : $inet; }
                    $names[] = $raw;
                }
                __mc_pdotls_names_free($sk);
            }
            if ($names === []) {
                $buf = \Runtime\Libc\calloc(256, 1);
                if ($buf !== null) {
                    if (__mc_pdotls_name_text(__mc_pdotls_subject($x), 13, $buf, 256) > 0) { $names[] = \cstr_to_str($buf); }   // NID_commonName
                    \Runtime\Libc\free($buf);
                }
            }
            if ($names !== []) {
                $res->certName = $names[0];
                $res->others = \count($names) - 1;
            }
        }
        __mc_pdotls_x509_free($x);
        return $ok;
    }
}
