<?php

namespace Runtime\Stdlib;

use Manticore\Attr\RefOut;

// DNS resolver — dns_get_record / checkdnsrr / getmxrr, hand-rolled over UDP/53 to
// the system nameserver. No libresolv (its query symbol diverges res_query vs
// res_9_query and its linkage is host-specific); a UDP query + a wire-format parser
// reuse only socket primitives and behave identically on every host. Names are
// parsed with compression-pointer support. The record arrays mirror php's shapes.

/**
 * resolv.conf's text, '' when it cannot be read. $path is a seam: every parser below
 * takes TEXT, so the whole of resolv.conf handling is testable offline, with no
 * network and no env var (the AOT runner injects none).
 */
function __mc_resolv_text(string $path = '/etc/resolv.conf'): string
{
    $c = \file_get_contents($path);
    return $c === false ? '' : $c;
}

/**
 * Every IPv4 `nameserver` in $text, comma-joined; '' when there is none. A
 * comma-joined STRING rather than an array so the value can cross any boundary
 * unharmed (the stdlib array-repr rule) — callers explode() it.
 */
function __mc_resolv_nameservers(string $text): string
{
    $out = '';
    foreach (\explode("\n", $text) as $line) {
        $line = \trim($line);
        if (\strpos($line, 'nameserver ') !== 0) { continue; }
        $ip = \trim(\substr($line, 11));
        // IPv4 only here (a v6 nameserver needs a v6 UDP socket path).
        if ($ip === '' || \strpos($ip, ':') !== false) { continue; }
        $out = $out === '' ? $ip : ($out . ',' . $ip);
    }
    return $out;
}

/**
 * The search list in $text, comma-joined; '' when there is none. `search a b c` and
 * `domain d` both feed it and the LAST such line wins — glibc's rule, and the reason
 * a file carrying both is not ambiguous. Capped at 6 entries (glibc's MAXSEARCH),
 * lowercased, trailing dots stripped.
 */
function __mc_resolv_search(string $text): string
{
    $out = '';
    foreach (\explode("\n", $text) as $line) {
        $line = \trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') { continue; }
        $isSearch = \strpos($line, 'search') === 0;
        $isDomain = \strpos($line, 'domain') === 0;
        if (!$isSearch && !$isDomain) { continue; }
        // `searchfoo bar` is not a search line: the keyword must end at a separator.
        $sep = \strlen($line) > 6 ? $line[6] : ' ';
        if ($sep !== ' ' && $sep !== "\t") { continue; }
        $rest = \trim(\substr($line, 6));
        if ($rest === '') { continue; }
        $out = '';
        $n = 0;
        foreach (\explode(' ', \str_replace("\t", ' ', $rest)) as $tokRaw) {
            $tok = \rtrim(\strtolower(\trim((string)$tokRaw)), '.');
            if ($tok === '') { continue; }
            $out = $out === '' ? $tok : ($out . ',' . $tok);
            $n = $n + 1;
            if ($n >= 6) { break; }
            if ($isDomain) { break; }   // `domain` names exactly one suffix
        }
    }
    return $out;
}

/**
 * `options ndots:N` from $text — last one wins, clamped to 0..15 (glibc's range);
 * 1 when absent. Other options (timeout:, attempts:, rotate, edns0) are ignored;
 * mapping timeout/attempts onto __mc_dns_query's 2 s × 2 tries is a follow-up.
 */
function __mc_resolv_ndots(string $text): int
{
    $nd = 1;
    foreach (\explode("\n", $text) as $line) {
        $line = \trim($line);
        if (\strpos($line, 'options') !== 0) { continue; }
        foreach (\explode(' ', \str_replace("\t", ' ', \substr($line, 7))) as $tokRaw) {
            $tok = \trim((string)$tokRaw);
            if (\strpos($tok, 'ndots:') !== 0) { continue; }
            $v = (int)\substr($tok, 6);
            if ($v < 0) { $v = 0; }
            if ($v > 15) { $v = 15; }
            $nd = $v;
        }
    }
    return $nd;
}

/**
 * The names to try for $host, in query order, comma-joined.
 *
 * An absolute name (`host.`) is used as-is. Otherwise the `ndots` rule decides which
 * comes first: a name with at least $ndots dots is likely already qualified, so the
 * bare name leads; a shorter one is a container/cluster short name (`db`, `redis`,
 * `service.namespace`) and the search suffixes lead. Inside compose and kubernetes
 * that shape is the NORM, and without it every such lookup fell through the async
 * resolver into the blocking getaddrinfo walk — stalling the whole loop, in exactly
 * the deployment async exists for.
 */
function __mc_dns_candidates(string $host, string $searchCsv, int $ndots): string
{
    $h = \rtrim($host, '.');
    if ($h === '' || \strlen($host) !== \strlen($h)) {
        return $h;   // rooted (or empty) — no search list applies
    }
    $suffixes = [];
    foreach (\explode(',', $searchCsv) as $sRaw) {
        $s = \trim((string)$sRaw);
        if ($s !== '') { $suffixes[] = $s; }
    }
    if (\count($suffixes) === 0) { return $h; }
    $qualified = \substr_count($h, '.') >= $ndots;
    $out = $qualified ? $h : '';
    foreach ($suffixes as $s) {
        $cand = $h . '.' . (string)$s;
        $out = $out === '' ? $cand : ($out . ',' . $cand);
    }
    return $qualified ? $out : ($out . ',' . $h);
}

/**
 * EVERY IPv4 `nameserver` from /etc/resolv.conf, comma-joined; '8.8.8.8' when the
 * file lists none.
 *
 * Resolvers are tried IN ORDER with a per-server timeout, which is what makes a dead
 * first entry survivable; a single-server resolver failed the whole lookup.
 */
function __mc_dns_nameservers(): string
{
    $out = \__mc_resolv_nameservers(\__mc_resolv_text());
    return $out === '' ? '8.8.8.8' : $out;
}

/** First `nameserver` (IPv4) from /etc/resolv.conf, or 8.8.8.8 as a fallback. */
function __mc_dns_nameserver(): string
{
    $c = \file_get_contents('/etc/resolv.conf');
    if ($c !== false) {
        $lines = \explode("\n", $c);
        foreach ($lines as $line) {
            $line = \trim($line);
            if (\strpos($line, 'nameserver ') === 0) {
                $ip = \trim(\substr($line, 11));
                // IPv4 only here (a v6 nameserver needs a v6 UDP socket path).
                if ($ip !== '' && \strpos($ip, ':') === false) {
                    return $ip;
                }
            }
        }
    }
    return '8.8.8.8';
}

/** Big-endian u16 as two bytes. */
function __mc_dns_u16b(int $v): string
{
    return \chr(($v >> 8) & 0xFF) . \chr($v & 0xFF);
}

/** A DNS QNAME: each dot-label length-prefixed, terminated by a 0 byte. */
function __mc_dns_qname(string $host): string
{
    $out = '';
    $labels = \explode('.', $host);
    foreach ($labels as $label) {
        if ($label === '') {
            continue;
        }
        $out = $out . \chr(\strlen($label)) . $label;
    }
    return $out . "\x00";
}

/** A standard recursive query packet for ($host, $qtype). */
function __mc_dns_build_query(string $host, int $qtype): string
{
    // id 0x1234 (fixed — a connected UDP socket only hears the one nameserver),
    // flags 0x0100 = RD, qdcount 1, the rest 0.
    $hdr = \__mc_dns_u16b(0x1234) . \__mc_dns_u16b(0x0100) . \__mc_dns_u16b(1)
         . \__mc_dns_u16b(0) . \__mc_dns_u16b(0) . \__mc_dns_u16b(0);
    return $hdr . \__mc_dns_qname($host) . \__mc_dns_u16b($qtype) . \__mc_dns_u16b(1);
}

/** u16 at byte offset $o. */
function __mc_dns_u16(string $m, int $o): int
{
    return (\ord($m[$o]) << 8) | \ord($m[$o + 1]);
}

/** u32 at byte offset $o. */
function __mc_dns_u32(string $m, int $o): int
{
    return (\ord($m[$o]) << 24) | (\ord($m[$o + 1]) << 16) | (\ord($m[$o + 2]) << 8) | \ord($m[$o + 3]);
}

/**
 * The offset immediately after the name at $off — used to walk RRs. A compression
 * pointer (top two bits set) ends the name in the wire in 2 bytes; an uncompressed
 * name ends at its 0 byte. Never follows the pointer (this only measures length).
 */
function __mc_dns_skip_name(string $m, int $off): int
{
    $n = \strlen($m);
    $guard = 0;
    while ($off < $n && $guard < 128) {
        $guard = $guard + 1;
        $len = \ord($m[$off]);
        if ($len === 0) {
            return $off + 1;
        }
        if (($len & 0xC0) === 0xC0) {
            return $off + 2;   // pointer — the name ends here in the wire
        }
        $off = $off + 1 + $len;
    }
    return $off;
}

/**
 * The decoded dotted name at $off, following compression pointers. Returns just the
 * name (the caller advances with __mc_dns_skip_name where it needs the end).
 */
function __mc_dns_read_name(string $m, int $off): string
{
    $n = \strlen($m);
    $labels = '';
    $guard = 0;
    while ($off < $n && $guard < 128) {
        $guard = $guard + 1;
        $len = \ord($m[$off]);
        if ($len === 0) {
            break;
        }
        if (($len & 0xC0) === 0xC0) {
            $off = (($len & 0x3F) << 8) | \ord($m[$off + 1]);   // jump
            continue;
        }
        $piece = \substr($m, $off + 1, $len);
        $labels = $labels === '' ? $piece : ($labels . '.' . $piece);
        $off = $off + 1 + $len;
    }
    return $labels;
}

/** php's DNS_* bit constant → the wire QTYPE, or 0 if unsupported. */
function __mc_dns_wire_type(int $bit): int
{
    if ($bit === 1) { return 1; }              // DNS_A
    if ($bit === 2) { return 2; }              // DNS_NS
    if ($bit === 16) { return 5; }             // DNS_CNAME
    if ($bit === 32) { return 6; }             // DNS_SOA
    if ($bit === 2048) { return 12; }          // DNS_PTR
    if ($bit === 8192) { return 257; }         // DNS_CAA
    if ($bit === 16384) { return 15; }         // DNS_MX
    if ($bit === 32768) { return 16; }         // DNS_TXT
    if ($bit === 33554432) { return 33; }      // DNS_SRV
    if ($bit === 134217728) { return 28; }     // DNS_AAAA
    return 0;
}

/**
 * Parse one answer RR at $rdoff (rdata start, $rdlen bytes) of wire $type into php's
 * record array, or an empty array for an unsupported type. $name is the owner, $ttl
 * the RR ttl.
 * @return array<string,mixed>
 */
function __mc_dns_parse_rr(string $m, int $type, string $name, int $ttl, int $rdoff, int $rdlen): array
{
    /** @var array<string,mixed> $rec */
    $rec = ['host' => $name, 'class' => 'IN', 'ttl' => $ttl];
    if ($type === 1) {
        $rec['type'] = 'A';
        $rec['ip'] = \ord($m[$rdoff]) . '.' . \ord($m[$rdoff + 1]) . '.' . \ord($m[$rdoff + 2]) . '.' . \ord($m[$rdoff + 3]);
        return $rec;
    }
    if ($type === 28) {
        $rec['type'] = 'AAAA';
        $v6 = '';
        for ($j = 0; $j < 8; $j = $j + 1) {
            $part = \dechex(\__mc_dns_u16($m, $rdoff + $j * 2));
            $v6 = $j === 0 ? $part : ($v6 . ':' . $part);
        }
        $rec['ipv6'] = $v6;
        return $rec;
    }
    if ($type === 15) {
        $rec['type'] = 'MX';
        $rec['pri'] = \__mc_dns_u16($m, $rdoff);
        $rec['target'] = \__mc_dns_read_name($m, $rdoff + 2);
        return $rec;
    }
    if ($type === 16) {
        $rec['type'] = 'TXT';
        $txt = '';
        $p = $rdoff;
        $end = $rdoff + $rdlen;
        while ($p < $end) {
            $l = \ord($m[$p]);
            $txt = $txt . \substr($m, $p + 1, $l);
            $p = $p + 1 + $l;
        }
        $rec['txt'] = $txt;
        return $rec;
    }
    if ($type === 5) {
        $rec['type'] = 'CNAME';
        $rec['target'] = \__mc_dns_read_name($m, $rdoff);
        return $rec;
    }
    if ($type === 2) {
        $rec['type'] = 'NS';
        $rec['target'] = \__mc_dns_read_name($m, $rdoff);
        return $rec;
    }
    if ($type === 12) {
        $rec['type'] = 'PTR';
        $rec['target'] = \__mc_dns_read_name($m, $rdoff);
        return $rec;
    }
    if ($type === 33) {
        $rec['type'] = 'SRV';
        $rec['pri'] = \__mc_dns_u16($m, $rdoff);
        $rec['weight'] = \__mc_dns_u16($m, $rdoff + 2);
        $rec['port'] = \__mc_dns_u16($m, $rdoff + 4);
        $rec['target'] = \__mc_dns_read_name($m, $rdoff + 6);
        return $rec;
    }
    if ($type === 6) {
        $rec['type'] = 'SOA';
        $rec['mname'] = \__mc_dns_read_name($m, $rdoff);
        $o = \__mc_dns_skip_name($m, $rdoff);
        $rec['rname'] = \__mc_dns_read_name($m, $o);
        $o = \__mc_dns_skip_name($m, $o);
        $rec['serial'] = \__mc_dns_u32($m, $o);
        $rec['refresh'] = \__mc_dns_u32($m, $o + 4);
        $rec['retry'] = \__mc_dns_u32($m, $o + 8);
        $rec['expire'] = \__mc_dns_u32($m, $o + 12);
        $rec['minimum-ttl'] = \__mc_dns_u32($m, $o + 16);
        return $rec;
    }
    if ($type === 257) {
        $rec['type'] = 'CAA';
        $rec['flags'] = \ord($m[$rdoff]);
        $taglen = \ord($m[$rdoff + 1]);
        $rec['tag'] = \substr($m, $rdoff + 2, $taglen);
        $rec['value'] = \substr($m, $rdoff + 2 + $taglen, $rdlen - 2 - $taglen);
        return $rec;
    }
    /** @var array<string,mixed> $empty */
    $empty = [];
    return $empty;
}

/**
 * Parse the answer section of $msg, keeping RRs of wire QTYPE $want.
 * @return array<int,array<string,mixed>>
 */
function __mc_dns_parse(string $msg, int $want): array
{
    /** @var array<int,array<string,mixed>> $out */
    $out = [];
    if (\strlen($msg) < 12) {
        return $out;
    }
    $qd = \__mc_dns_u16($msg, 4);
    $an = \__mc_dns_u16($msg, 6);
    $off = 12;
    for ($i = 0; $i < $qd; $i = $i + 1) {
        $off = \__mc_dns_skip_name($msg, $off) + 4;   // + QTYPE + QCLASS
    }
    for ($i = 0; $i < $an; $i = $i + 1) {
        $name = \__mc_dns_read_name($msg, $off);   // RR owner
        $off = \__mc_dns_skip_name($msg, $off);
        $type = \__mc_dns_u16($msg, $off);
        $ttl = \__mc_dns_u32($msg, $off + 4);
        $rdlen = \__mc_dns_u16($msg, $off + 8);
        $rdoff = $off + 10;
        if ($type === $want) {
            $rr = \__mc_dns_parse_rr($msg, $type, $name, $ttl, $rdoff, $rdlen);
            if (\count($rr) > 0) {
                $out[] = $rr;
            }
        }
        $off = $rdoff + $rdlen;
    }
    return $out;
}

/** Send $query to $sock and return the raw response, or '' on timeout. */
function __mc_dns_exchange(\Resource $sock, string $query, float $timeout = 5.0): string
{
    $fd = $sock->addr;
    if (\Runtime\Libc\sys_send($fd, $query, \strlen($query), 0) < 0) {
        return '';
    }
    // Under a scheduler the budget is served by the reactor's BOUNDED wait, so name
    // resolution no longer stops the loop. It must stay BOUNDED: a lost datagram has
    // no EOF to wake the fiber, and an unbounded park would hang it.
    if (\Runtime\AsyncHook::active()) {
        $hf = \Runtime\AsyncHook::readableFor();
        if ($hf($sock, $timeout) !== true) {
            return '';   // no reply in time
        }
    } elseif (\__mc_poll_readable($fd, (int)($timeout * 1000.0)) === 0) {
        return '';   // no reply in time
    }
    $buf = \Runtime\Libc\calloc(4096, 1);
    if ($buf === null) {
        return '';
    }
    $got = \Runtime\Libc\sys_recv($fd, $buf, 4096, 0);
    // Length-based: a DNS message is binary and may hold NUL bytes.
    $resp = $got > 0 ? \str_from_buffer($buf, $got) : '';
    \Runtime\Libc\free($buf);
    return $resp;
}

/** True when the reply's TC bit is set — the answer did not fit in one datagram. */
function __mc_dns_truncated(string $msg): bool
{
    if (\strlen($msg) < 12) {
        return false;
    }
    return (\ord($msg[2]) & 0x02) !== 0;   // flags byte 1, bit 1 = TC
}

/**
 * Repeat the query over TCP, which is what TC means: RFC 1035 says a truncated UDP
 * answer must be retried on 53/tcp. The wire format is the same message with a
 * 2-byte big-endian length prefix in both directions.
 *
 * Without this, a host with many A records (or any large RRset) resolved to whatever
 * fragment fit in 512 bytes — or to nothing at all.
 */
function __mc_dns_exchange_tcp(string $ns, string $query, float $timeout = 5.0): string
{
    $sock = \__mc_tcp_connect($ns, 53, 1);   // 1 = SOCK_STREAM
    if ($sock === false) {
        return '';
    }
    $len = \strlen($query);
    $framed = \chr(($len >> 8) & 0xFF) . \chr($len & 0xFF) . $query;
    \stream_set_timeout($sock, (int)$timeout, 0);
    if (\fwrite($sock, $framed) < 2) {
        \fclose($sock);
        return '';
    }
    $hdr = \fread($sock, 2);
    if ($hdr === false || \strlen($hdr) < 2) {
        \fclose($sock);
        return '';
    }
    $want = (\ord($hdr[0]) << 8) | \ord($hdr[1]);
    $body = '';
    while (\strlen($body) < $want) {
        $chunk = \fread($sock, $want - \strlen($body));
        if ($chunk === false || $chunk === '') { break; }
        $body = $body . $chunk;
    }
    \fclose($sock);
    return \strlen($body) === $want ? $body : '';
}

/**
 * Query the system nameservers for ($host, wire $qtype) and return the raw reply.
 *
 * Walks EVERY nameserver from resolv.conf, twice each, with a 2 s per-attempt budget:
 * a dead or slow first entry no longer fails the whole lookup (it used to take the
 * first line and give up). A truncated (TC) answer is retried over TCP.
 */
function __mc_dns_query(string $host, int $qtype): string
{
    return \__mc_dns_query_via($host, $qtype, \__mc_dns_nameservers());
}

/** A reply's RCODE: 0 NOERROR, 3 NXDOMAIN; -1 when the message is too short. */
function __mc_dns_rcode(string $msg): int
{
    if (\strlen($msg) < 4) { return -1; }
    return \ord($msg[3]) & 0x0F;
}

/**
 * Last query outcome holder, the {@see __mc_net_errno} idiom (an int static: the
 * stdlib cannot own an assoc, and a string static's repr across the boundary is
 * untested). -1 = no server answered at all, which is what stops a search walk.
 */
function __mc_dns_rcode_hold(bool $write, int $val): int
{
    static $r = -1;
    if ($write) { $r = $val; }
    return $r;
}

/** The RCODE of the last query, or -1 when nobody answered. */
function __mc_dns_rcode_last(): int
{
    return \__mc_dns_rcode_hold(false, 0);
}

/**
 * {@see __mc_dns_query} against an explicit nameserver list, so a caller walking a
 * search list parses resolv.conf ONCE instead of once per candidate. Records the
 * reply's RCODE for that walk to read back.
 */
function __mc_dns_query_via(string $host, int $qtype, string $nsCsv): string
{
    \__mc_dns_rcode_hold(true, -1);
    $servers = \explode(',', $nsCsv);
    $query = \__mc_dns_build_query($host, $qtype);
    foreach ($servers as $nsRaw) {
        $ns = (string)$nsRaw;
        if ($ns === '') { continue; }
        for ($try = 0; $try < 2; $try = $try + 1) {
            $sock = \__mc_tcp_connect($ns, 53, 2);   // 2 = SOCK_DGRAM (connected UDP)
            if ($sock === false) { break; }          // cannot reach it at all
            $resp = \__mc_dns_exchange($sock, $query, 2.0);
            \fclose($sock);
            if ($resp === '') { continue; }          // timed out — retry this server
            if (\__mc_dns_truncated($resp)) {
                $full = \__mc_dns_exchange_tcp($ns, $query, 2.0);
                if ($full !== '') {
                    \__mc_dns_rcode_hold(true, \__mc_dns_rcode($full));
                    return $full;
                }
            }
            \__mc_dns_rcode_hold(true, \__mc_dns_rcode($resp));
            return $resp;
        }
    }
    return '';
}

/**
 * php.net's dns_get_record: the resource records for $hostname of the requested
 * $type (a DNS_* bitmask). Returns an array of record arrays, or false on failure.
 * The by-ref authoritative/additional sections are not populated here (php fills
 * them from the reply's authority/additional; apps rarely read them).
 *
 * DNS_ANY / DNS_ALL fan out over the common types. A single DNS_* bit sends one
 * query. Unknown bits yield an empty result.
 * @return array<int,array<string,mixed>>|false
 */
function dns_get_record(string $hostname, int $type = 268435456, #[RefOut] array &$authoritative_name_servers = [], #[RefOut] array &$additional_records = [], bool $raw = false)
{
    $authoritative_name_servers = [];
    $additional_records = [];
    // The type set to query: an explicit single bit, or the common fan-out for
    // DNS_ANY (268435456) / DNS_ALL (268435455).
    /** @var int[] $bits */
    $bits = [];
    if ($type === 268435456 || $type === 268435455) {
        $bits = [1, 2, 16, 16384, 32768, 32, 134217728];   // A, NS, CNAME, MX, TXT, SOA, AAAA
    } else {
        $bits = [$type];
    }
    /** @var array<int,array<string,mixed>> $out */
    $out = [];
    foreach ($bits as $bit) {
        $wire = \__mc_dns_wire_type($bit);
        if ($wire === 0) {
            continue;
        }
        $msg = \__mc_dns_query($hostname, $wire);
        if ($msg === '') {
            continue;
        }
        $recs = \__mc_dns_parse($msg, $wire);
        foreach ($recs as $r) {
            $out[] = $r;
        }
    }
    return $out;
}

/**
 * php.net's checkdnsrr: whether any DNS record of $type exists for $hostname.
 * $type is a STRING ('A','MX','NS','SOA','PTR','CNAME','AAAA','SRV','TXT','CAA','ANY').
 */
function checkdnsrr(string $hostname, string $type = 'MX'): bool
{
    $t = \strtoupper($type);
    $wire = 0;
    if ($t === 'A') { $wire = 1; }
    elseif ($t === 'NS') { $wire = 2; }
    elseif ($t === 'CNAME') { $wire = 5; }
    elseif ($t === 'SOA') { $wire = 6; }
    elseif ($t === 'PTR') { $wire = 12; }
    elseif ($t === 'MX') { $wire = 15; }
    elseif ($t === 'TXT') { $wire = 16; }
    elseif ($t === 'AAAA') { $wire = 28; }
    elseif ($t === 'SRV') { $wire = 33; }
    elseif ($t === 'CAA') { $wire = 257; }
    elseif ($t === 'ANY') { $wire = 255; }
    if ($wire === 0) {
        return false;
    }
    $msg = \__mc_dns_query($hostname, $wire === 255 ? 1 : $wire);
    if ($msg === '') {
        return false;
    }
    // ANY: any answer counts; else count only matching-type answers.
    if ($wire === 255) {
        return \strlen($msg) >= 8 && \__mc_dns_u16($msg, 6) > 0;
    }
    return \count(\__mc_dns_parse($msg, $wire)) > 0;
}

/** Alias of checkdnsrr, php.net's dns_check_record. */
function dns_check_record(string $hostname, string $type = 'MX'): bool
{
    return \checkdnsrr($hostname, $type);
}

/**
 * php.net's getmxrr: the MX records for $hostname into &$hosts (targets) and
 * &$weights (priorities). Returns true when at least one MX record was found.
 * @param string[] $hosts
 * @param int[] $weights
 */
function getmxrr(string $hostname, #[RefOut] array &$hosts, #[RefOut] array &$weights = []): bool
{
    $hosts = [];
    $weights = [];
    $msg = \__mc_dns_query($hostname, 15);
    if ($msg === '') {
        return false;
    }
    $recs = \__mc_dns_parse($msg, 15);
    foreach ($recs as $r) {
        $hosts[] = $r['target'];
        $weights[] = $r['pri'];
    }
    return \count($hosts) > 0;
}
