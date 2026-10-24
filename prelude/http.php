<?php

// Http\ — an HTTP/1.1 server, and the byte-level wire codec under it.
// DEMAND-GATED (Main.php): only a program that mentions `Http\` carries any of it.
//
// Everything lives here, in the prelude, rather than splitting the scalar half
// into src/Runtime/Stdlib the way session.php ↔ Session.php does. That split buys
// a smaller per-program IR and costs the thing this code needs most: TYPES. The
// stdlib `.o.sig` carries functions only, so every array crossing it has to be
// re-declared to keep its element type, no helper may name a `Buffer\ByteBuffer`
// or an `Http\Headers`, and a parser that wants to hand a buffer to a helper has
// to pass `(string $buf, int $pos)` and scan it twice. In one compilation unit
// none of that exists: the helpers take the real objects, Monomorphize and
// InlineClosures see through the whole path, and the element-erasure traps that
// live at that boundary have no boundary to live at.
//
// Splitting the genuinely scalar leaves back out into stdlib.o later is a
// mechanical refactor and a tracked debt, not a design change.
//
// Two conventions run through the file, and both are deliberate:
//
//   - A failure is a SENTINEL, never a union. `int` with `-1`, `array<…>` with
//     `[]`. A `int|false` return makes the value a CELL at every call site, and
//     this code compares these against arithmetic constantly.
//   - Every array return is DECLARED with its element type. A bare `array`
//     erases to KIND_UNKNOWN and the caller then reads each element raw
//     (`2.1E-314` instead of a string).
//
// RFC references are to RFC 7230 unless stated otherwise.

namespace Http {

/**
 * `explode()` with the element type re-established.
 *
 * Its own function because `explode()` is declared to answer a BARE `array`, so
 * its elements ride erased and `$parts[0]` — an index read, which never unboxes
 * — comes back as a raw pointer. A declared return type fixes it once, and the
 * body is small enough that the compiler inlines it away.
 *
 * @internal
 * @return array<int,string>
 */
function splitStr(string $sep, string $s): array<int, string>
{
    return \explode($sep, $s);
}

/**
 * The reason phrase for a status code, or `''` for a code that has no phrase here.
 *
 * Covers the 1xx-5xx codes listed in `Status`. `Status::text()` calls this.
 *
 * @param $code  HTTP status code.
 * @return string  The phrase (`404` gives `Not Found`), or an empty string for an unknown code.
 */
// The reason phrase for a status code, or '' when we do not know it.
//
// A `match`, and a plain one: the table is written once for the whole process,
// so the arms cost nothing per request. This is also why {@see Status} is a
// class of `const int` rather than a backed enum — 42 enum cases would mean
// `from`/`tryFrom`/`cases()` arms in the IR of every program that serves HTTP,
// to produce a value that goes on the wire as an int.
function reason(int $code): string
{
    return match ($code) {
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        206 => 'Partial Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Content',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        default => '',
    };
}

/**
 * `strtolower`, but free when there is nothing to lower.
 *
 * Header lookups are the hottest string operation in the server, and almost
 * all of them pass a name that is ALREADY lowercase — every `get('host')`,
 * `get('content-length')`, `has('connection')` in this file, plus the map key
 * of any field a client sent lowercase. `strtolower` allocates a fresh string
 * regardless, so those were an allocation each, per request. Scanning for an
 * uppercase byte first is a read-only pass that usually ends the work.
 *
 * @internal
 */
function lowerName(string $s): string
{
    $n = \strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = \ord($s[$i]);
        if ($c >= 65 && $c <= 90) {
            return \strtolower($s);
        }
    }
    return $s;
}

/**
 * Whether every byte of $s is an RFC 7230 `tchar`.
 *
 * The method name and every header field name must pass this. It is not
 * cosmetic: a field name holding a space or a colon is how request smuggling
 * gets past a front proxy, so a failure here is a 400, not a normalisation.
 *
 * @internal
 */
function tokenOk(string $s): bool
{
    $n = \strlen($s);
    if ($n === 0) {
        return false;
    }
    for ($i = 0; $i < $n; $i++) {
        $c = \ord($s[$i]);
        if ($c >= 48 && $c <= 57) { continue; }             // 0-9
        if ($c >= 65 && $c <= 90) { continue; }             // A-Z
        if ($c >= 97 && $c <= 122) { continue; }            // a-z
        // "!#$%&'*+-.^_`|~"
        if ($c === 33 || $c === 35 || $c === 36 || $c === 37 || $c === 38
            || $c === 39 || $c === 42 || $c === 43 || $c === 45 || $c === 46
            || $c === 94 || $c === 95 || $c === 96 || $c === 124 || $c === 126) {
            continue;
        }
        return false;
    }
    return true;
}

/**
 * Offset of the CRLFCRLF that ends the head, searching from $from, or -1.
 *
 * Answers the offset of the FIRST byte of the terminator, so the head itself is
 * `substr($s, $from, $end - $from)` and the body starts at `$end + 4`.
 *
 * @internal
 */
function headEnd(string $s, int $from): int
{
    $p = \strpos($s, "\r\n\r\n", $from);
    if ($p === false) {
        return -1;
    }
    return $p;
}

/**
 * Split a head block into lines, unfolding obs-fold continuations.
 *
 * $head must NOT include the terminating CRLFCRLF. Line 0 is the request line;
 * every line after it is a header field. A continuation line (one starting with
 * SP or HTAB, §3.2.4) is joined to its predecessor with a single SP — obs-fold
 * is deprecated but still arrives, and accepting it costs less than rejecting a
 * request mid-block.
 *
 * A leading continuation (a fold with nothing to fold into) is malformed:
 * answers [] so the caller can 400.
 *
 * @internal
 * @return array<int,string>
 */
function splitHead(string $head): array<int, string>
{
    $raw = splitStr("\r\n", $head);
    $out = [];
    $n = 0;
    foreach ($raw as $line) {
        if ($line === '') {
            continue;
        }
        $c = $line[0];
        if ($c === ' ' || $c === "\t") {
            if ($n === 0) {
                return [];
            }
            $out[$n - 1] = $out[$n - 1] . ' ' . \trim($line);
            continue;
        }
        $out[$n] = $line;
        $n++;
    }
    return $out;
}

/**
 * Split a request line into exactly [method, target, version], or [] when it is
 * malformed.
 *
 * Strict on purpose: exactly two spaces, a token method, and a version that
 * starts `HTTP/`. Everything a tolerant parser would repair here is something a
 * front proxy might read differently — which is the whole smuggling class.
 *
 * @internal
 * @return array<int,string>
 */
function reqLine(string $line): array<int, string>
{
    $a = \strpos($line, ' ');
    if ($a === false || $a === 0) {
        return [];
    }
    $b = \strpos($line, ' ', $a + 1);
    if ($b === false || $b === $a + 1) {
        return [];
    }
    if (\strpos($line, ' ', $b + 1) !== false) {
        return [];
    }
    $method = \substr($line, 0, $a);
    $target = \substr($line, $a + 1, $b - $a - 1);
    $version = \substr($line, $b + 1);
    if (!tokenOk($method)) {
        return [];
    }
    if ($target === '' || \strncmp($version, 'HTTP/', 5) !== 0) {
        return [];
    }
    $out = [];
    $out[0] = $method;
    $out[1] = $target;
    $out[2] = \substr($version, 5);
    return $out;
}

/**
 * Split a header line into [name, value], or [] when it is malformed.
 *
 * The whitespace-before-colon check is a security rule, not tidiness: §3.2.4
 * forbids it precisely because two intermediaries disagree about whether
 * `Content-Length : 5` is a Content-Length. Reject it.
 *
 * @internal
 * @return array<int,string>
 */
function headerSplit(string $line): array<int, string>
{
    $c = \strpos($line, ':');
    if ($c === false || $c === 0) {
        return [];
    }
    $name = \substr($line, 0, $c);
    if (!tokenOk($name)) {
        return [];
    }
    $out = [];
    $out[0] = $name;
    $out[1] = \trim(\substr($line, $c + 1), " \t");
    return $out;
}

/**
 * Split a request-target into exactly [path, query]. The query keeps its
 * percent-encoding; the path is not decoded here ({@see normPath}).
 *
 * @internal
 * @return array<int,string>
 */
function splitPath(string $target): array<int, string>
{
    $out = [];
    $q = \strpos($target, '?');
    if ($q === false) {
        $out[0] = $target;
        $out[1] = '';
        return $out;
    }
    $out[0] = \substr($target, 0, $q);
    $out[1] = \substr($target, $q + 1);
    return $out;
}

/**
 * `host:port` / `[v6]:port` → [host, port], port '' when absent. Splits on the
 * LAST colon so a bare v6 address is not cut at its first group.
 *
 * @internal
 * @return array<int, string>
 */
function splitHostPort(string $hp): array<int, string>
{
    $out = [];
    $colon = \strrpos($hp, ':');
    $close = \strrpos($hp, ']');
    // No colon, `[v6]` with no port, or a BARE v6 (two or more colons and no
    // brackets — `2001:db8::7` has no port to split off).
    if ($colon === false
        || ($close !== false && $colon < $close)
        || ($close === false && \strpos($hp, ':') !== $colon)) {
        $out[] = \trim($hp, '[]');
        $out[] = '';
        return $out;
    }
    $out[] = \trim(\substr($hp, 0, $colon), '[]');
    $out[] = \substr($hp, $colon + 1);
    return $out;
}

/**
 * Parse `a.b.c.d/n` / `x::y/n` / a bare address into [packed network bytes,
 * prefix length as a decimal string], or null when malformed. The prefix is a
 * string because the pair rides in one `array<int,string>` — a mixed tuple
 * would make both elements cells.
 *
 * @internal
 * @return ?array<int, string>
 */
function cidrParse(string $cidr): ?array<int, string>
{
    if ($cidr === '') {
        return null;
    }
    $slash = \strpos($cidr, '/');
    $addr = $slash === false ? $cidr : \substr($cidr, 0, $slash);
    $packed = \inet_pton($addr);
    if ($packed === false) {
        return null;
    }
    $bits = \strlen($packed) * 8;
    $len = $bits;
    if ($slash !== false) {
        $p = \substr($cidr, $slash + 1);
        if ($p === '' || !\ctype_digit($p)) {
            return null;
        }
        $len = (int)$p;
        if ($len > $bits) {
            return null;
        }
    }
    $out = [];
    $out[] = $packed;
    $out[] = (string)$len;
    return $out;
}

/**
 * @internal
 * @param array<int, string> $net from {@see cidrParse}
 */
function inCidrParsed(string $packedIp, array<int, string> $net): bool
{
    $network = $net[0];
    $len = (int)$net[1];
    if (\strlen($packedIp) !== \strlen($network)) {
        return false;
    }
    $full = \intdiv($len, 8);
    for ($i = 0; $i < $full; $i = $i + 1) {
        if ($packedIp[$i] !== $network[$i]) {
            return false;
        }
    }
    $rem = $len % 8;
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (\ord($packedIp[$full]) & $mask) === (\ord($network[$full]) & $mask);
}

/**
 * Whether an IP address lies inside a CIDR network.
 *
 * A bare address without a prefix is treated as `/32` (IPv4) or `/128` (IPv6). A malformed address
 * or network gives `false`. An IPv4 address never matches an IPv6 network or the reverse,
 * including the v4-mapped `::ffff:a.b.c.d` form.
 *
 * @param $ip  Address to test, IPv4 or IPv6 text form.
 * @param $cidr  Network such as `10.0.0.0/8` or `2001:db8::/32`.
 * @return bool  `true` when `$ip` is inside `$cidr`.
 */
// Is `$ip` inside `$cidr`? A bare address is /32 or /128. Malformed → false.
function inCidr(string $ip, string $cidr): bool
{
    $net = cidrParse($cidr);
    if ($net === null) {
        return false;
    }
    $packed = \inet_pton($ip);
    if ($packed === false) {
        return false;
    }
    return inCidrParsed($packed, $net);
}

/**
 * Resolve the client behind a trusted proxy.
 *
 * `$trusted` is the parsed CIDR list; `$flags` are {@see Proxy} bits. Returns
 * [remoteAddr, '1'|'0' for secure, forwardedPort] and rewrites `Host` in `$h`
 * when HOST is granted. A peer outside `$trusted` gets its own address back
 * and the headers are ignored, not stripped.
 *
 * `Proxy::FORWARDED` is opt-in — {@see Proxy::ALL} does not carry it, since a
 * proxy that merely passes a client-supplied `Forwarded:` through is exactly
 * as unsafe as trusting an arbitrary `X-Forwarded-*`. When granted and the
 * header is present, its elements' `for=` values form the SAME right-to-left
 * chain as `X-Forwarded-For` (one walk, whichever family supplies it, and
 * `Forwarded` wins when it names `for` at all); `proto=`/`host=` come from
 * the RIGHTMOST element that names them — the hop nearest this process. Each
 * field stays gated by its own flag too: `for` needs `FOR`, `proto` needs
 * `PROTO`, `host` needs `HOST`. A hop `\inet_pton` rejects (`unknown`, an
 * obfuscated identifier, malformed) is skipped exactly like an empty element;
 * if every hop is junk, `remoteAddr` stays the peer.
 *
 * @internal
 * @param array<int, array<int, string>> $trusted
 * @return array<int, string>
 */
function resolveForwarded(Headers $h, string $peer, bool $secure, array $trusted, int $flags): array<int, string>
{
    $out = [];
    $out[] = $peer;
    $out[] = $secure ? '1' : '0';
    $out[] = '';
    if (!proxyTrusted(peerIp($peer), $trusted)) {
        return $out;
    }
    $for = '';
    $proto = '';
    $host = '';
    $port = '';
    if (($flags & Proxy::FOR) !== 0) {
        $for = $h->get('x-forwarded-for');
    }
    if (($flags & Proxy::PROTO) !== 0) {
        $proto = firstToken($h->get('x-forwarded-proto'));
    }
    if (($flags & Proxy::HOST) !== 0) {
        $host = firstToken($h->get('x-forwarded-host'));
    }
    if (($flags & Proxy::PORT) !== 0) {
        $port = firstToken($h->get('x-forwarded-port'));
    }
    $hops = $for === '' ? [] : splitStr(',', $for);
    if (($flags & Proxy::FORWARDED) !== 0) {
        $fwd = $h->get('forwarded');
        if ($fwd !== '') {
            $fwdHops = [];
            $namedFor = false;
            foreach (splitStr(',', $fwd) as $el) {
                $elFor = '';
                foreach (splitStr(';', \trim($el)) as $pair) {
                    $eq = \strpos($pair, '=');
                    if ($eq === false) {
                        continue;
                    }
                    $k = \strtolower(\trim(\substr($pair, 0, $eq)));
                    $v = \trim(\substr($pair, $eq + 1), " \t\"");
                    if ($k === 'for') {
                        $elFor = $v;
                        $namedFor = true;
                    } elseif ($k === 'proto' && ($flags & Proxy::PROTO) !== 0) {
                        $proto = $v;
                    } elseif ($k === 'host' && ($flags & Proxy::HOST) !== 0) {
                        $host = $v;
                    }
                }
                $fwdHops[] = $elFor;
            }
            // `for` still needs its own flag even with FORWARDED granted; when
            // Forwarded names no `for=` at all, the X-Forwarded-For chain (if
            // any) set above stands.
            if ($namedFor && ($flags & Proxy::FOR) !== 0) {
                $hops = $fwdHops;
            }
        }
    }
    $chosen = '';
    for ($i = \count($hops) - 1; $i >= 0; $i = $i - 1) {
        $ip = hopIp($hops[$i]);
        if ($ip === '') {
            continue;
        }
        $chosen = $ip;
        if (!proxyTrusted($ip, $trusted)) {
            break;
        }
    }
    if ($chosen !== '') {
        $out[0] = $chosen;
    }
    if ($proto !== '') {
        $out[1] = \strtolower($proto) === 'https' ? '1' : '0';
    }
    if ($host !== '') {
        $h->set('Host', $host);
    }
    if ($port !== '' && \ctype_digit($port) && (int)$port >= 1 && (int)$port <= 65535) {
        $out[2] = $port;
    }
    return $out;
}

/**
 * @internal
 * @param array<int, array<int, string>> $trusted
 */
function proxyTrusted(string $ip, array $trusted): bool
{
    if (\count($trusted) === 0) {
        return false;
    }
    $packed = \inet_pton($ip);
    if ($packed === false) {
        return false;
    }
    foreach ($trusted as $net) {
        if (inCidrParsed($packed, $net)) {
            return true;
        }
    }
    return false;
}

/**
 * The first comma-separated element, trimmed.
 *
 * @internal
 */
function firstToken(string $v): string
{
    $c = \strpos($v, ',');
    return \trim($c === false ? $v : \substr($v, 0, $c));
}

/**
 * A forwarded hop's IP, or '' when it is not one — `unknown`, an obfuscated
 * identifier, or anything else `\inet_pton` rejects. Skipped exactly like an
 * empty element in the walk; the hop's own port (if any) is discarded the
 * same way {@see splitHostPort} discards one.
 *
 * @internal
 */
function hopIp(string $hop): string
{
    $ip = splitHostPort(\trim($hop))[0];
    if ($ip === '' || \inet_pton($ip) === false) {
        return '';
    }
    return $ip;
}

/**
 * The peer's address with no port. `stream_socket_get_name()` answers
 * Zend-format `host:port` with NO brackets even for v6 (`::1:54321`), and the
 * peer ALWAYS carries a port — so this splits at the LAST colon
 * unconditionally rather than reusing {@see splitHostPort}'s bare-v6
 * heuristic, which is right for a `Host` header or a forwarded hop but wrong
 * here (it would refuse to split `::1:54321` at all).
 *
 * @internal
 */
function peerIp(string $peer): string
{
    $colon = \strrpos($peer, ':');
    if ($colon === false) {
        return \trim($peer, '[]');
    }
    return \trim(\substr($peer, 0, $colon), '[]');
}

/**
 * Percent-decode a path and collapse its `.` and `..` segments.
 *
 * A server that hands `..` to a handler is a path-traversal generator, so this
 * runs on every request. `..` past the root is dropped, not propagated: the
 * result always stays rooted. A NUL byte answers '' — the caller 400s, because
 * a truncating consumer downstream is the other half of the same bug class.
 *
 * ⚠ Decoding happens BEFORE the split, so `%2F` becomes a segment separator:
 * `/a%2Fb` normalises to `/a/b`. RFC 3986 says an encoded slash is not a
 * separator, and a router matching segments can be confused by the difference —
 * which is why {@see Request} keeps the raw request-target too. `$path` is the
 * decoded view, `$target` is the wire truth; that is the same pair Go's
 * `URL.Path` / `URL.RawPath` draws, and a handler that cares reads the second.
 *
 * @internal
 */
function normPath(string $path): string
{
    if ($path === '' || $path === '*') {
        return $path;
    }
    $dec = \rawurldecode($path);
    if (\strpos($dec, "\0") !== false) {
        return '';
    }
    $trailing = false;
    if (\strlen($dec) > 1 && $dec[\strlen($dec) - 1] === '/') {
        $trailing = true;
    }
    $parts = splitStr('/', $dec);
    $stack = [];
    $depth = 0;
    foreach ($parts as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            if ($depth > 0) {
                $depth--;
            }
            continue;
        }
        $stack[$depth] = $seg;
        $depth++;
    }
    $out = '';
    for ($i = 0; $i < $depth; $i++) {
        $out = $out . '/' . $stack[$i];
    }
    if ($out === '') {
        return '/';
    }
    if ($trailing) {
        return $out . '/';
    }
    return $out;
}

/**
 * Parse an `a=1&b=2` query string, flat and last-wins.
 *
 * Flat: `?a[]=1` is the key `a[]` here. The php-shaped nested form is
 * {@see parseQueryNested}, built separately and only on demand. `+` is a space
 * here (urldecode, not rawurldecode) — that is the form-encoding rule, and it is
 * why the query and the path decode differently.
 *
 * @internal
 * @return array<string,string>
 */
function parseQuery(string $qs): array<string, string>
{
    $out = [];
    if ($qs === '') {
        return $out;
    }
    $pairs = splitStr('&', $qs);
    foreach ($pairs as $pair) {
        if ($pair === '') {
            continue;
        }
        $e = \strpos($pair, '=');
        if ($e === false) {
            $out[\urldecode($pair)] = '';
            continue;
        }
        $out[\urldecode(\substr($pair, 0, $e))] = \urldecode(\substr($pair, $e + 1));
    }
    return $out;
}

/**
 * php's GPC key rule, one pair at a time: the WHOLE key is urldecoded first,
 * then `.` and space in the base become `_`, brackets nest, `[]` appends, a
 * canonical decimal segment is an int key. A copy of the stdlib's
 * parse_str assign with a `mixed` value, because a $_FILES column is an int.
 *
 * `$decode = false` skips the urldecode: a multipart part name is already
 * bytes, and php's rfc1867 registers it as sent (`a%20b` stays `a%20b`).
 *
 * Oracle: `php -r 'parse_str($qs, $r); var_dump($r);'`.
 *
 * The `$node = $arr[$base]; …; $arr[$base] = $node;` read-modify-write is
 * deliberate: a by-ref into an element of a cell-element array is the erased
 * channel W4 has not closed; a local copy plus a store keeps every level typed.
 *
 * @internal
 * @param array<int|string, mixed> $arr
 */
function nestedAssign(array &$arr, string $rawKey, mixed $val, bool $decode = true): void
{
    $key = $decode ? \urldecode($rawKey) : $rawKey;
    $bpos = \strpos($key, '[');
    $base = $bpos === false ? $key : \substr($key, 0, $bpos);
    $base = \str_replace(['.', ' '], '_', $base);
    if ($base === '') {
        return;
    }
    // `0=x` lands at [0], as every segment below does: php canonicalises the
    // base too, and a runtime string key does not canonicalise on the store.
    // The array is cell-keyed ({@see \Manticore\Sapi\Context::$emptyGpc}), so an
    // int here is an int entry every reader — foreach included — sees as one.
    $bk = canonicalIntKey($base) ? (int)$base : $base;
    if ($bpos === false) {
        $arr[$bk] = $val;
        return;
    }
    $segs = [];
    $s = \substr($key, $bpos);
    $n = \strlen($s);
    $i = 0;
    $broken = false;
    while ($i < $n) {
        if ($s[$i] !== '[') {
            break;
        }
        $close = \strpos($s, ']', $i);
        if ($close === false) {
            $broken = true;
            break;
        }
        $segs[] = \substr($s, $i + 1, $close - $i - 1);
        $i = $close + 1;
    }
    if ($broken && \count($segs) === 0) {
        // `g[` — php keeps the rest of the key literally, with `[` as `_`.
        $arr[$base . \str_replace(['.', ' ', '['], '_', $s)] = $val;
        return;
    }
    if (\count($segs) === 0) {
        $arr[$bk] = $val;
        return;
    }
    if (!isset($arr[$bk]) || !\is_array($arr[$bk])) {
        $arr[$bk] = \Manticore\Sapi\Context::$emptyGpc;
    }
    $node = $arr[$bk];
    nestedWalk($node, $segs, 0, $val);
    $arr[$bk] = $node;
}

/**
 * @internal
 * @param array<int|string, mixed> $node
 * @param array<int, string> $segs
 */
function nestedWalk(array &$node, array $segs, int $idx, mixed $val): void
{
    $seg = $segs[$idx];
    $last = $idx === \count($segs) - 1;
    if ($seg === '') {
        if ($last) {
            $node[] = $val;
            return;
        }
        $child = \Manticore\Sapi\Context::$emptyGpc;
        nestedWalk($child, $segs, $idx + 1, $val);
        $node[] = $child;
        return;
    }
    $k = canonicalIntKey($seg) ? (int)$seg : $seg;
    if ($last) {
        $node[$k] = $val;
        return;
    }
    if (!isset($node[$k]) || !\is_array($node[$k])) {
        $node[$k] = \Manticore\Sapi\Context::$emptyGpc;
    }
    $child = $node[$k];
    nestedWalk($child, $segs, $idx + 1, $val);
    $node[$k] = $child;
}

/**
 * "0" or a digit run with no leading zero, optionally negated; ≤ 18 digits.
 *
 * @internal
 */
function canonicalIntKey(string $s): bool
{
    $n = \strlen($s);
    if ($n === 0) {
        return false;
    }
    $i = $s[0] === '-' ? 1 : 0;
    if ($i >= $n) {
        return false;
    }
    if ($s[$i] === '0') {
        return $n - $i === 1 && $i === 0;
    }
    for ($j = $i; $j < $n; $j = $j + 1) {
        $c = \ord($s[$j]);
        if ($c < 48 || $c > 57) {
            return false;
        }
    }
    return $n - $i <= 18;
}

/**
 * php's $_GET/$_POST shape: nested, `max_input_vars`-capped. The flat
 * {@see parseQuery} stays for `query()`; this one is built on first use.
 *
 * @internal
 * @return array<int|string, mixed>
 */
function parseQueryNested(string $qs, int $maxVars): array<int|string, mixed>
{
    $out = \Manticore\Sapi\Context::$emptyGpc;
    if ($qs === '') {
        return $out;
    }
    $seen = 0;
    foreach (splitStr('&', $qs) as $pair) {
        if ($pair === '') {
            continue;
        }
        if ($seen >= $maxVars) {
            break;
        }
        $seen = $seen + 1;
        $e = \strpos($pair, '=');
        if ($e === false) {
            nestedAssign($out, $pair, '');
            continue;
        }
        nestedAssign($out, \substr($pair, 0, $e), \urldecode(\substr($pair, $e + 1)));
    }
    return $out;
}

/**
 * A chunk source over a string.
 *
 * @internal
 */
function stringSource(string $s): \Closure
{
    $pos = 0;
    return function (int $max) use ($s, &$pos): string {
        $out = \substr($s, $pos, $max);
        $pos = $pos + \strlen($out);
        return $out;
    };
}

/**
 * Offset of the whole-word `param=` in a Content-Disposition value, -1 when
 * absent: `filename=` must not match inside `name=` nor inside a quoted
 * `name="xfilename=1"`, and vice versa.
 *
 * @internal
 */
function dispositionParamPos(string $v, string $param): int
{
    $needle = $param . '=';
    $pos = 0;
    while (true) {
        $p = \stripos($v, $needle, $pos);
        if ($p === false) {
            return -1;
        }
        if ($p > 0 && $v[$p - 1] !== ';' && $v[$p - 1] !== ' ' && $v[$p - 1] !== "\t") {
            $pos = $p + 1;
            continue;
        }
        return $p;
    }
}

/**
 * `name="…"` / `filename="…"` from a Content-Disposition value. Inside the
 * quotes only `\\` and `\"` are escapes — php's substring_conf — so a bare
 * `C:\dir\x.txt` keeps its backslashes; `%22` decodes to `"`.
 *
 * @internal
 */
function dispositionParam(string $v, string $param): string
{
    $p = dispositionParamPos($v, $param);
    if ($p < 0) {
        return '';
    }
    $s = $p + \strlen($param) + 1;
    if ($s < \strlen($v) && $v[$s] === '"') {
        $out = '';
        $i = $s + 1;
        $n = \strlen($v);
        while ($i < $n) {
            $c = $v[$i];
            if ($c === '\\' && $i + 1 < $n && ($v[$i + 1] === '\\' || $v[$i + 1] === '"')) {
                $out = $out . $v[$i + 1];
                $i = $i + 2;
                continue;
            }
            if ($c === '"') {
                break;
            }
            $out = $out . $c;
            $i = $i + 1;
        }
        return \str_replace('%22', '"', $out);
    }
    $semi = \strpos($v, ';', $s);
    return \trim($semi === false ? \substr($v, $s) : \substr($v, $s, $semi - $s));
}

/**
 * Parse a `Cookie:` header value into name => value, last-wins.
 *
 * Values are urldecoded, matching what php puts in $_COOKIE for what setcookie()
 * rawurlencoded on the way out.
 *
 * @internal
 * @return array<string,string>
 */
function parseCookies(string $line): array<string, string>
{
    $out = [];
    if ($line === '') {
        return $out;
    }
    $parts = splitStr(';', $line);
    foreach ($parts as $part) {
        $p = \trim($part);
        if ($p === '') {
            continue;
        }
        $e = \strpos($p, '=');
        if ($e === false || $e === 0) {
            continue;
        }
        $out[\trim(\substr($p, 0, $e))] = \urldecode(\substr($p, $e + 1));
    }
    return $out;
}

/**
 * Maps a request path to a regular file under a document root, or returns `null`.
 *
 * The root and the candidate both go through `realpath`, so `..` segments and symlinks that leave
 * the root are refused. A directory, a missing file, a missing root, an empty path, a path that
 * does not start with `/` and any path ending in `/` all answer `null`: which index file a
 * directory stands for is the handler's policy. Pass `Request::$path`, which the server has
 * already percent-decoded and `..`-collapsed.
 *
 * ```php
 * $file = Http\safePath($root, $req->path);
 * return $file === null ? (new Response(404))->text('not found') : (new Response())->file($file);
 * ```
 *
 * @param $root  Directory to serve files from. It must exist.
 * @param $reqPath  Request path, normally `$request->path`. It must start with `/`.
 * @return ?string  The real path of a regular file inside `$root`, or `null` when there is none.
 * @example doc_http_static_safe_path
 * @see Response
 */
// A request path mapped under `$root`, or null when it does not name a regular
// file inside it.
//
// Both sides go through `realpath`, so a symlink that leaves the root is
// refused by construction rather than by string surgery on `..`. A DIRECTORY
// answers null: which index file a directory stands for is the handler's
// policy, not this function's (`safePath($root, $req->path . '/index.html')`).
//
// The path arrives already percent-decoded and `..`-collapsed by the server
// ({@see normPath}), so an encoded `%2e%2e` is a literal path segment here and
// simply names nothing.
function safePath(string $root, string $reqPath): ?string
{
    if ($reqPath === '' || $reqPath[0] !== '/') {
        return null;
    }
    // A trailing slash spells a DIRECTORY in a URL, and a directory answers
    // null here. Without the test `realpath` decides: Darwin resolves
    // `/pub/index.html/` to the file and Linux refuses it, which would make
    // the same request answer differently per host.
    if (\substr($reqPath, -1) === '/') {
        return null;
    }
    $base = \realpath($root);
    if ($base === false) {
        return null;
    }
    $base = \rtrim($base, '/');
    $full = \realpath($base . $reqPath);
    if ($full === false || !\is_file($full)) {
        return null;
    }
    if (\strncmp($full, $base . '/', \strlen($base) + 1) !== 0) {
        return null;
    }
    return $full;
}

/**
 * The `Content-Type` for a file name, chosen by its extension (case-insensitive).
 *
 * Text types carry `charset=utf-8`. An unknown or missing extension gives
 * `application/octet-stream`. `Response::file()` uses this when no type is passed.
 *
 * @param $path  File path or name. Only the part after the last `.` of the last path segment matters.
 * @return string  A media type such as `text/html; charset=utf-8` or `image/png`.
 */
// Content-Type from the extension; `application/octet-stream` when unknown.
function mimeFor(string $path): string
{
    $dot = \strrpos($path, '.');
    $slash = \strrpos($path, '/');
    if ($dot === false || ($slash !== false && $dot < $slash)) {
        return 'application/octet-stream';
    }
    $ext = \strtolower(\substr($path, $dot + 1));
    return match ($ext) {
        'html', 'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js', 'mjs' => 'text/javascript; charset=utf-8',
        'json', 'map' => 'application/json',
        'txt', 'md', 'log' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'wasm' => 'application/wasm',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'csv' => 'text/csv; charset=utf-8',
        'webmanifest' => 'application/manifest+json',
        default => 'application/octet-stream',
    };
}

/**
 * Does `Accept-Encoding` grant gzip? A `gzip` token whose q is not 0, or a `*`
 * that grants it when no `gzip` token settles the question first.
 *
 * @internal
 */
function acceptsGzip(string $ae): bool
{
    if ($ae === '') {
        return false;
    }
    $star = false;
    foreach (splitStr(',', $ae) as $part) {
        $p = \trim($part);
        $q = 1.0;
        $semi = \strpos($p, ';');
        $name = $p;
        if ($semi !== false) {
            $name = \trim(\substr($p, 0, $semi));
            $params = \strtolower(\substr($p, $semi + 1));
            $qp = \strpos($params, 'q=');
            if ($qp !== false) {
                $q = (float)\trim(\substr($params, $qp + 2));
            }
        }
        $name = \strtolower($name);
        if ($name === 'gzip') {
            return $q > 0.0;
        }
        if ($name === '*') {
            $star = $q > 0.0;
        }
    }
    return $star;
}

/**
 * Text-like content types worth deflating.
 *
 * @internal
 */
function compressible(string $ct): bool
{
    $semi = \strpos($ct, ';');
    $t = \strtolower(\trim($semi === false ? $ct : \substr($ct, 0, $semi)));
    if (\strncmp($t, 'text/', 5) === 0) {
        return true;
    }
    if ($t === 'application/json' || $t === 'application/javascript' || $t === 'application/xml'
        || $t === 'application/xhtml+xml' || $t === 'image/svg+xml' || $t === 'application/wasm'
        || $t === 'application/manifest+json') {
        return true;
    }
    $plus = \strrpos($t, '+');
    if ($plus !== false) {
        $suffix = \substr($t, $plus + 1);
        return $suffix === 'json' || $suffix === 'xml';
    }
    return false;
}

/**
 * Does an `If-None-Match` list match `$etag`? WEAK comparison, which is the
 * only one RFC 9110 §13.1.2 allows for `If-None-Match`: both `W/` prefixes are
 * dropped before comparing, and `*` matches anything.
 *
 * @internal
 */
function etagMatches(string $list, string $etag): bool
{
    $want = \strncmp($etag, 'W/', 2) === 0 ? \substr($etag, 2) : $etag;
    foreach (splitStr(',', $list) as $t) {
        $tag = \trim($t);
        if ($tag === '*') {
            return true;
        }
        if (\strncmp($tag, 'W/', 2) === 0) {
            $tag = \substr($tag, 2);
        }
        if ($tag === $want) {
            return true;
        }
    }
    return false;
}

/**
 * `bytes=a-b` → `[first, last]`, inclusive and clamped to the file.
 *
 * null means UNSATISFIABLE (416). `[-1, -1]` means "not a single byte range I
 * serve" — a multi-range, another unit, or garbage — which the RFC lets a
 * server answer with the full representation.
 *
 * @internal
 * @return ?array<int, int>
 */
function parseRange(string $spec, int $size): ?array<int, int>
{
    $ignore = [];
    $ignore[] = -1;
    $ignore[] = -1;
    if (\strncmp($spec, 'bytes=', 6) !== 0 || \strpos($spec, ',') !== false) {
        return $ignore;
    }
    $r = \trim(\substr($spec, 6));
    $dash = \strpos($r, '-');
    if ($dash === false) {
        return $ignore;
    }
    $a = \substr($r, 0, $dash);
    $b = \substr($r, $dash + 1);
    if ($a === '') {
        // `-n`: the LAST n bytes. An empty suffix and a zero-length one are
        // unsatisfiable, and so is any suffix of an empty file.
        if ($b === '' || !\ctype_digit($b)) {
            return $ignore;
        }
        $n = (int)$b;
        if ($n === 0 || $size === 0) {
            return null;
        }
        if ($n > $size) {
            $n = $size;
        }
        $out = [];
        $out[] = $size - $n;
        $out[] = $size - 1;
        return $out;
    }
    if (!\ctype_digit($a) || ($b !== '' && !\ctype_digit($b))) {
        return $ignore;
    }
    $first = (int)$a;
    if ($first >= $size) {
        return null;
    }
    $last = $b === '' ? $size - 1 : (int)$b;
    if ($last < $first) {
        return $ignore;
    }
    if ($last >= $size) {
        $last = $size - 1;
    }
    $out = [];
    $out[] = $first;
    $out[] = $last;
    return $out;
}

/**
 * Byte length of the chunk size-line starting at $pos, CRLF included; -1 when
 * the buffer does not hold a complete one yet, -2 when it is too long to be one.
 *
 * Paired with {@see chunkSize} rather than returning both numbers at once: a
 * `[len, size]` array invites a heterogeneous shape, and a mixed-element return
 * erases its type (an int reads back as 2.06E-321). The size line is at most 32
 * bytes, so scanning it twice is free.
 *
 * The -2 arm matters: a size line nobody terminates is either garbage or an
 * attempt to make the server buffer without bound.
 *
 * @internal
 */
function chunkHdr(string $buf, int $pos): int
{
    $p = \strpos($buf, "\r\n", $pos);
    if ($p === false) {
        if (\strlen($buf) - $pos > 32) {
            return -2;
        }
        return -1;
    }
    if ($p - $pos > 32) {
        return -2;
    }
    return $p + 2 - $pos;
}

/**
 * The chunk size at $pos, or -1 when the hex field is missing or malformed.
 *
 * A chunk-ext (`;name=value`) after the size is parsed off and discarded, which
 * is what every server does with it. More than 15 hex digits is refused rather
 * than wrapped: a size that overflows into a small positive number is how a body
 * cap gets bypassed.
 *
 * @internal
 */
function chunkSize(string $buf, int $pos): int
{
    $n = \strlen($buf);
    $i = $pos;
    $digits = 0;
    $val = 0;
    while ($i < $n) {
        $c = \ord($buf[$i]);
        $d = -1;
        if ($c >= 48 && $c <= 57) { $d = $c - 48; }
        elseif ($c >= 97 && $c <= 102) { $d = $c - 87; }
        elseif ($c >= 65 && $c <= 70) { $d = $c - 55; }
        if ($d < 0) {
            break;
        }
        if ($digits >= 15) {
            return -1;
        }
        $val = $val * 16 + $d;
        $digits++;
        $i++;
    }
    if ($digits === 0 || $i >= $n) {
        return -1;
    }
    $t = $buf[$i];
    if ($t === "\r" || $t === ';' || $t === ' ' || $t === "\t") {
        return $val;
    }
    return -1;
}

/**
 * Frame $data as one HTTP chunk: hex length, CRLF, bytes, CRLF.
 *
 * @internal
 */
function chunkFrame(string $data): string
{
    return \dechex(\strlen($data)) . "\r\n" . $data . "\r\n";
}

/**
 * `HTTP/<version> <code> <reason>` plus its CRLF.
 *
 * @internal
 */
function statusLine(int $code, string $version): string
{
    // The handful of codes a real server actually emits, as whole literals:
    // every response otherwise built one from three concatenations and a
    // 42-arm reason() lookup. These arms are a select over .rodata.
    if ($version === '1.1') {
        if ($code === 200) { return "HTTP/1.1 200 OK\r\n"; }
        if ($code === 204) { return "HTTP/1.1 204 No Content\r\n"; }
        if ($code === 301) { return "HTTP/1.1 301 Moved Permanently\r\n"; }
        if ($code === 302) { return "HTTP/1.1 302 Found\r\n"; }
        if ($code === 304) { return "HTTP/1.1 304 Not Modified\r\n"; }
        if ($code === 400) { return "HTTP/1.1 400 Bad Request\r\n"; }
        if ($code === 404) { return "HTTP/1.1 404 Not Found\r\n"; }
        if ($code === 500) { return "HTTP/1.1 500 Internal Server Error\r\n"; }
    }
    $reason = reason($code);
    if ($reason === '') {
        return 'HTTP/' . $version . ' ' . $code . "\r\n";
    }
    return 'HTTP/' . $version . ' ' . $code . ' ' . $reason . "\r\n";
}

/**
 * Render header lines into a wire block, terminator included.
 *
 * @internal
 * @param array<int,string> $lines
 */
function renderLines(array<int, string> $lines): string
{
    $out = '';
    foreach ($lines as $line) {
        $out = $out . $line . "\r\n";
    }
    return $out . "\r\n";
}

/**
 * An IMF-fixdate for a `Date:` header — always GMT, always the same width.
 *
 * @internal
 */
function httpDate(int $ts): string
{
    return \gmdate('D, d M Y H:i:s', $ts) . ' GMT';
}

/**
 * Whether the connection must close after this message.
 *
 * The two versions invert the default, which is the whole rule: 1.1 is
 * persistent unless it says `close`, 1.0 is not unless it says `keep-alive`.
 * One function so the two call sites (request framing, response framing) cannot
 * drift apart.
 *
 * @internal
 */
function connClose(string $connectionHdr, string $version): bool
{
    $tokens = splitStr(',', \strtolower($connectionHdr));
    $close = false;
    $keep = false;
    foreach ($tokens as $t) {
        $tok = \trim($t);
        if ($tok === 'close') { $close = true; }
        if ($tok === 'keep-alive') { $keep = true; }
    }
    if ($version === '1.0') {
        return !$keep;
    }
    return $close;
}

/**
 * Whether a Transfer-Encoding value ends in `chunked`.
 *
 * Only the LAST coding decides framing (§3.3.1). A `Transfer-Encoding` whose
 * last token is anything else has no length the server can determine, and
 * §3.3.3 rule 3 says that is a 400 — so the caller distinguishes "absent" from
 * "present but not chunked" itself.
 *
 * @internal
 */
function teIsChunked(string $te): bool
{
    $tokens = splitStr(',', \strtolower($te));
    $last = '';
    foreach ($tokens as $t) {
        $tok = \trim($t);
        if ($tok !== '') {
            $last = $tok;
        }
    }
    return $last === 'chunked';
}

/**
 * A Content-Length value as an int, or -1 when it is malformed.
 *
 * Digits only — no sign, no whitespace inside, no `+`. A repeated header arrives
 * here comma-joined (`5,5`), and php's own rule applies: identical values are
 * one value, differing values are a smuggling attempt and answer -1.
 *
 * @internal
 */
function contentLength(string $v): int
{
    $s = \trim($v);
    if ($s === '') {
        return -1;
    }
    if (\strpos($s, ',') !== false) {
        $parts = splitStr(',', $s);
        $first = '';
        foreach ($parts as $p) {
            $t = \trim($p);
            if ($first === '') {
                $first = $t;
                continue;
            }
            if ($t !== $first) {
                return -1;
            }
        }
        $s = $first;
    }
    $n = \strlen($s);
    if ($n === 0 || $n > 18) {
        return -1;
    }
    $val = 0;
    for ($i = 0; $i < $n; $i++) {
        $c = \ord($s[$i]);
        if ($c < 48 || $c > 57) {
            return -1;
        }
        $val = $val * 10 + ($c - 48);
    }
    return $val;
}

/**
 * The nine HTTP methods defined by RFC 9110, as a typed view of the request method token.
 *
 * `Request::$method` stays a raw string: methods outside this list (`PROPFIND`, `PURGE`) are legal
 * and have no case here. Use `Request::methodEnum()` for an exhaustive `match`, or
 * `Request::is(Method::Post)` for a single test.
 *
 * @example doc_http_status_method
 * @see Request
 */
// The nine methods RFC 9110 defines, as a typed lens over the wire token.
//
// {@see Request::$method} stays a raw `string` and this enum is optional on
// purpose: WebDAV's `PROPFIND`, a CDN's `PURGE` and anything else a handler is
// entitled to answer have no case here, and a `?Method` on the hot path would
// put a null check in front of every route test. `methodEnum()` is for the
// handler that wants exhaustive `match`; `$method === 'GET'` stays legal.
enum Method: string
{
    /** `GET`: retrieve a representation of the resource. */
    case Get = 'GET';
    /** `HEAD`: like `GET`, headers only. */
    case Head = 'HEAD';
    /** `POST`: submit data for processing. */
    case Post = 'POST';
    /** `PUT`: replace the resource. */
    case Put = 'PUT';
    /** `PATCH`: partially modify the resource. */
    case Patch = 'PATCH';
    /** `DELETE`: remove the resource. */
    case Delete = 'DELETE';
    /** `OPTIONS`: ask what the resource supports. */
    case Options = 'OPTIONS';
    /** `TRACE`: echo the request back for diagnosis. */
    case Trace = 'TRACE';
    /** `CONNECT`: open a tunnel (the server answers 501). */
    case Connect = 'CONNECT';

    /**
     * No side effects expected of the origin (§9.2.1).
     *
     * @return bool  `true` for `GET`, `HEAD`, `OPTIONS` and `TRACE`.
     */
    public function isSafe(): bool
    {
        return $this === Method::Get || $this === Method::Head
            || $this === Method::Options || $this === Method::Trace;
    }

    /**
     * Repeating it must read the same as doing it once (§9.2.2).
     *
     * @return bool  `true` for the safe methods plus `PUT` and `DELETE`.
     */
    public function isIdempotent(): bool
    {
        return $this->isSafe() || $this === Method::Put || $this === Method::Delete;
    }

    /**
     * Whether a REQUEST body is meaningful. Not the same as "may carry one":
     * a GET with a body is legal on the wire and forbidden to mean anything, so
     * the framing rules read this to decide whether a missing Content-Length is
     * a 411 or simply an empty request.
     *
     * @return bool  `true` for `POST`, `PUT` and `PATCH`.
     */
    public function allowsBody(): bool
    {
        return $this === Method::Post || $this === Method::Put || $this === Method::Patch;
    }
}

/**
 * HTTP status codes as integer constants, plus helpers for the response path.
 *
 * A class of `const int` rather than an enum, so the value is a plain `int` everywhere it is
 * compared or sent. Pass a constant wherever a status is expected: `new
 * Response(Status::NOT_FOUND)`.
 *
 * @example doc_http_status_method
 * @see Response
 */
// Status codes, and the two predicates the response path needs.
//
// A class of `const int`, not a backed enum, and the reason is IR volume: 42
// enum cases put `from`/`tryFrom`/`cases()` arms into every program that serves
// HTTP, to model a value that is an `int` on the wire and an `int` in every
// comparison. {@see text} delegates to {@see reason}, so the table is written
// once for the whole process.
final class Status
{
    /** `100 Continue`. */
    public const CONTINUE = 100;
    /** `101 Switching Protocols`. */
    public const SWITCHING_PROTOCOLS = 101;
    /** `200 OK`. */
    public const OK = 200;
    /** `201 Created`. */
    public const CREATED = 201;
    /** `202 Accepted`. */
    public const ACCEPTED = 202;
    /** `204 No Content`. */
    public const NO_CONTENT = 204;
    /** `206 Partial Content`. */
    public const PARTIAL_CONTENT = 206;
    /** `301 Moved Permanently`. */
    public const MOVED_PERMANENTLY = 301;
    /** `302 Found`. */
    public const FOUND = 302;
    /** `303 See Other`. */
    public const SEE_OTHER = 303;
    /** `304 Not Modified`. */
    public const NOT_MODIFIED = 304;
    /** `307 Temporary Redirect`. */
    public const TEMPORARY_REDIRECT = 307;
    /** `308 Permanent Redirect`. */
    public const PERMANENT_REDIRECT = 308;
    /** `400 Bad Request`. */
    public const BAD_REQUEST = 400;
    /** `401 Unauthorized`. */
    public const UNAUTHORIZED = 401;
    /** `403 Forbidden`. */
    public const FORBIDDEN = 403;
    /** `404 Not Found`. */
    public const NOT_FOUND = 404;
    /** `405 Method Not Allowed`. */
    public const METHOD_NOT_ALLOWED = 405;
    /** `406 Not Acceptable`. */
    public const NOT_ACCEPTABLE = 406;
    /** `408 Request Timeout`. */
    public const REQUEST_TIMEOUT = 408;
    /** `409 Conflict`. */
    public const CONFLICT = 409;
    /** `410 Gone`. */
    public const GONE = 410;
    /** `411 Length Required`. */
    public const LENGTH_REQUIRED = 411;
    /** `412 Precondition Failed`. */
    public const PRECONDITION_FAILED = 412;
    /** `413 Content Too Large`. */
    public const CONTENT_TOO_LARGE = 413;
    /** `414 URI Too Long`. */
    public const URI_TOO_LONG = 414;
    /** `415 Unsupported Media Type`. */
    public const UNSUPPORTED_MEDIA_TYPE = 415;
    /** `416 Range Not Satisfiable`. */
    public const RANGE_NOT_SATISFIABLE = 416;
    /** `417 Expectation Failed`. */
    public const EXPECTATION_FAILED = 417;
    /** `421 Misdirected Request`. */
    public const MISDIRECTED_REQUEST = 421;
    /** `422 Unprocessable Content`. */
    public const UNPROCESSABLE_CONTENT = 422;
    /** `425 Too Early`. */
    public const TOO_EARLY = 425;
    /** `426 Upgrade Required`. */
    public const UPGRADE_REQUIRED = 426;
    /** `428 Precondition Required`. */
    public const PRECONDITION_REQUIRED = 428;
    /** `429 Too Many Requests`. */
    public const TOO_MANY_REQUESTS = 429;
    /** `431 Request Header Fields Too Large`. */
    public const REQUEST_HEADER_FIELDS_TOO_LARGE = 431;
    /** `500 Internal Server Error`. */
    public const INTERNAL_SERVER_ERROR = 500;
    /** `501 Not Implemented`. */
    public const NOT_IMPLEMENTED = 501;
    /** `502 Bad Gateway`. */
    public const BAD_GATEWAY = 502;
    /** `503 Service Unavailable`. */
    public const SERVICE_UNAVAILABLE = 503;
    /** `504 Gateway Timeout`. */
    public const GATEWAY_TIMEOUT = 504;
    /** `505 HTTP Version Not Supported`. */
    public const HTTP_VERSION_NOT_SUPPORTED = 505;

    /**
     * The reason phrase for a status code, or `''` for a code without one.
     *
     * @param $code  HTTP status code.
     * @return string  The phrase, for example `Not Found`.
     */
    // The reason phrase, or '' for a code we do not name.
    public static function text(int $code): string
    {
        return reason($code);
    }

    /**
     * Whether a code is one of the redirects `redirect()` is meant for.
     *
     * @param $code  HTTP status code.
     * @return bool  `true` for 301, 302, 303, 307 and 308.
     */
    public static function isRedirect(int $code): bool
    {
        return $code === 301 || $code === 302 || $code === 303
            || $code === 307 || $code === 308;
    }

    /**
     * Whether a response with this code may carry a body at all (§6.4.1).
     * 1xx, 204 and 304 may not — not even a `Content-Length: 0`.
     *
     * @param $code  HTTP status code.
     * @return bool  `false` for 1xx, 204 and 304; `true` otherwise.
     */
    public static function hasBody(int $code): bool
    {
        if ($code >= 100 && $code < 200) {
            return false;
        }
        return $code !== 204 && $code !== 304;
    }
}

/**
 * Bit flags that say which forwarded headers a trusted proxy may set.
 *
 * Pass them as the second argument of `Server::trustedProxies()`. Combine with `|`. `ALL` is the
 * four `X-Forwarded-*` headers; `FORWARDED` (RFC 7239 `Forwarded:`) is not part of it and must be
 * granted explicitly, because a proxy that merely passes a client-sent `Forwarded:` header through
 * makes it as untrustworthy as any other header.
 *
 * @example doc_http_proxy
 * @see Server
 */
// Which forwarded headers a trusted proxy may set. `const int` flags, not an
// enum, for the same reason {@see Status} is not one.
//
// `FORWARDED` is NOT in `ALL` — opt in explicitly. A proxy that merely passes
// a client-supplied `Forwarded:` header through is exactly as unsafe as
// trusting an arbitrary `X-Forwarded-*`; RFC 7239 support is offered, not
// defaulted.
final class Proxy
{
    /** Trust `X-Forwarded-For` to set `Request::$remoteAddr`. */
    public const FOR = 1;
    /** Trust `X-Forwarded-Proto` to set `Request::$secure`. */
    public const PROTO = 2;
    /** Trust `X-Forwarded-Host` to replace the `Host` header. */
    public const HOST = 4;
    /** Trust `X-Forwarded-Port` to set `Request::$forwardedPort`. */
    public const PORT = 8;
    /**
     * Also read the RFC 7239 `Forwarded:` header. Each field is still gated by its own flag (`FOR`,
     * `PROTO`, `HOST`).
     */
    public const FORWARDED = 16;
    /** `FOR | PROTO | HOST | PORT` (15). Does not include `FORWARDED`. */
    public const ALL = 15;
}

/**
 * A set of HTTP header fields: case-insensitive lookup on top of the ordered wire lines.
 *
 * The lookup side answers "what is the `Content-Type`": names are case-insensitive and a repeated
 * field is comma-joined (`a, b`). The wire side keeps every line in the order it was added, and
 * does not join repeats, which is what `Set-Cookie` needs. `Request::$headers` holds what the
 * client sent; `Response::$headers` holds what will be sent.
 *
 * `get()`, `has()`, `int()` and `all()` use the lookup side. `lines()` and `render()` use the wire
 * side.
 *
 * @example doc_http_headers
 * @see Request
 * @see Response
 */
// A header block: a lookup by lowercased name, and the wire lines in order.
//
// Two structures rather than one because the two questions are different. A
// handler asks "what is the Content-Type" — one hash probe, case-insensitive,
// repeats comma-joined the way §5.2 says a recipient may. The wire asks "what
// exactly do I send" — order preserved, and `Set-Cookie` repeated rather than
// joined, because joining it is the one case §5.2 explicitly excludes.
//
// ⚠ Both properties carry a DECLARED element type, and that is load-bearing:
// `private array $map = []` types `assoc[string,unknown]`, and every index read
// off it then comes back as a raw pointer instead of a string.
final class Headers
{
    /**
     * @var array<string,string> lowercased name => value, repeats comma-joined
     *
     * Built EAGERLY, and that was measured rather than assumed — twice, both
     * times against this. On a 12-header request answering ~7 lookups, plus a
     * response that is only rendered (200k iterations each):
     *
     *   eager map (this)   parse 5.33 µs   build 563 ns
     *   scan, no map       parse 6.64 µs   build 423 ns
     *   map built lazily   parse 6.28 µs   build 429 ns
     *
     * Scanning loses on the request because the parser ALREADY has the name
     * and the value split ({@see headerSplit}), so filling the map costs no
     * extra parsing — while any scheme that rebuilds it from the rendered
     * block re-splits every line. (Comparing names in place instead of through
     * `substr` did not rescue scanning: the cost is walking, not allocating.)
     *
     * The best possible hybrid — eager on requests, none on responses — is
     * 5.76 µs against this 5.90 µs per request+response cycle. 2.3%, for a
     * flag on the class and two code paths through every accessor. Not taken.
     */
    private array<string, string> $map = [];

    /**
     * The wire block: `Name: value\r\n` per field, insertion order, no
     * terminator.
     *
     * A STRING, not an array of lines, and it is the hot path that decides
     * that. Every field appended cost an array slot and every render() walked
     * them to concatenate — with two Headers per request, array_set_int was
     * the single biggest source of malloc traffic in the server profile.
     * `.=` on a property is the amortized in-place append, so building the
     * block IS the render. lines() still answers the array form for the rare
     * caller that wants it.
     */
    private string $block = '';

    /** The reset values, as properties rather than `[]` literals: an empty
     *  literal types its element `unknown`, and the stores that follow would
     *  then write raw values under readers that expect strings. Same reason
     *  {@see \Manticore\Sapi\Context::$empty} exists. */
    private static array<string, string> $emptyMap = [];
    private static array<int, string> $emptyLines = [];

    /**
     * Builds a header set from raw `Name: value` lines.
     *
     * A line without a colon, with an empty name, or with a name that is not a valid HTTP token is
     * skipped. Whitespace around the value is trimmed. Lines are not unfolded: pass one complete field
     * per element.
     *
     * @param $lines  Header lines without the trailing CRLF.
     * @return Headers  A new `Headers` holding the valid lines, in order.
     */
    // Build from wire lines, as they came off the head.
    public static function fromLines(array<int, string> $lines): Headers
    {
        $h = new Headers();
        foreach ($lines as $line) {
            $kv = headerSplit($line);
            if (\count($kv) === 2) {
                $h->add($kv[0], $kv[1]);
            }
        }
        return $h;
    }

    /**
     * Whether a field is present. The name is case-insensitive.
     *
     * @param $n  Field name.
     * @return bool  `true` if at least one line with that name exists.
     */
    public function has(string $n): bool
    {
        return isset($this->map[lowerName($n)]);
    }

    /**
     * The value of a field. The name is case-insensitive.
     *
     * If the field occurs more than once, the values are joined with `, ` in the order they were
     * added.
     *
     * @param $n  Field name.
     * @param $default  Returned when the field is absent.
     * @return string  The value, or `$default`.
     */
    public function get(string $n, string $default = ''): string
    {
        $k = lowerName($n);
        if (!isset($this->map[$k])) {
            return $default;
        }
        return $this->map[$k];
    }

    /**
     * The value as an int, or $default when absent or not all digits.
     *
     * @param $n  Field name.
     * @param $default  Returned when the field is absent, empty, longer than 18 digits, or contains anything but digits.
     * @return int  The value as an integer, or `$default`.
     */
    public function int(string $n, int $default = 0): int
    {
        $k = lowerName($n);
        if (!isset($this->map[$k])) {
            return $default;
        }
        $v = $this->map[$k];
        $len = \strlen($v);
        if ($len === 0 || $len > 18) {
            return $default;
        }
        $out = 0;
        for ($i = 0; $i < $len; $i++) {
            $c = \ord($v[$i]);
            if ($c < 48 || $c > 57) {
                return $default;
            }
            $out = $out * 10 + ($c - 48);
        }
        return $out;
    }

    /**
     * Sets a field, replacing every existing line with that name.
     *
     * The new line goes to the end of the wire order and keeps the capitalisation of `$n` as written.
     *
     * @param $n  Field name.
     * @param $v  Field value.
     */
    // Replace every occurrence of $n with a single line.
    public function set(string $n, string $v): void
    {
        $k = lowerName($n);
        $this->dropLines($k);
        $this->map[$k] = $v;
        $this->block .= $n . ': ' . $v . "\r\n";
    }

    /**
     * Appends a field line and keeps any lines that are already there.
     *
     * Lookup (`get()`) then answers the values comma-joined; the wire gets one line per call, which is
     * what `Set-Cookie` needs.
     *
     * @param $n  Field name.
     * @param $v  Field value.
     */
    // Append a line, keeping any that are already there. The lookup value is
    // comma-joined; the wire keeps both lines, which is what `Set-Cookie` needs.
    public function add(string $n, string $v): void
    {
        $k = lowerName($n);
        if (isset($this->map[$k])) {
            $this->map[$k] = $this->map[$k] . ', ' . $v;
        } else {
            $this->map[$k] = $v;
        }
        $this->block .= $n . ': ' . $v . "\r\n";
    }

    /**
     * Removes every line with this name. The name is case-insensitive; an absent name is not an error.
     *
     * @param $n  Field name.
     */
    public function remove(string $n): void
    {
        $k = lowerName($n);
        unset($this->map[$k]);
        $this->dropLines($k);
    }

    /**
     * The number of distinct field names (a repeated field counts once).
     *
     * @return int  The count.
     */
    // Distinct field names.
    public function count(): int
    {
        return \count($this->map);
    }

    /**
     * All fields for lookup: lowercased name to value, repeats comma-joined.
     *
     * @return array<string,string> lowercased name => value
     */
    public function all(): array<string, string>
    {
        return $this->map;
    }

    /**
     * The wire lines, in the order they were added, one `Name: value` string per line without the
     * CRLF.
     *
     * @return array<int,string> the wire lines, in order
     */
    //
    // Split on demand. Nothing on the response path calls this — render()
    // hands the block over whole — so the array only ever exists for a caller
    // that genuinely wants one (the SAPI absorption, a test).
    public function lines(): array<int, string>
    {
        $out = [];
        if ($this->block === '') {
            return $out;
        }
        $n = 0;
        foreach (splitStr("\r\n", $this->block) as $line) {
            if ($line !== '') {
                $out[$n] = $line;
                $n++;
            }
        }
        return $out;
    }

    /**
     * The block as it goes on the wire: every line followed by CRLF, then the empty line that ends a
     * head.
     *
     * @return string  The serialized block.
     */
    // The wire block, terminating CRLF included.
    public function render(): string
    {
        return $this->block . "\r\n";
    }

    /** Removes every field. */
    // Drop every field.
    public function clear(): void
    {
        $this->map = self::$emptyMap;
        $this->block = '';
    }

    /**
     * Replaces this set with a copy of another, keeping repeated lines and their order.
     *
     * @param $o  The set to copy.
     */
    // Become a copy of `$o`.
    //
    // Through the public wire lines rather than the private fields, so repeats
    // and their order survive exactly — which is what the SAPI absorption needs
    // (`Set-Cookie` accumulates, everything else replaces).
    public function copyFrom(Headers $o): void
    {
        $this->clear();
        foreach ($o->lines() as $line) {
            $kv = headerSplit($line);
            if (\count($kv) === 2) {
                $this->add($kv[0], $kv[1]);
            }
        }
    }

    /**
     * Drop every wire line whose name is $lower.
     *
     * Rebuilt into a fresh DECLARED array rather than filtered in place:
     * `array_filter`/`array_values` answer a bare `array`, which erases the
     * element type this class depends on.
     */
    private function dropLines(string $lower): void
    {
        if ($this->block === '') {
            return;
        }
        $kept = '';
        $pos = 0;
        $len = \strlen($this->block);
        while ($pos < $len) {
            $eol = \strpos($this->block, "\r\n", $pos);
            if ($eol === false) {
                break;
            }
            $c = \strpos($this->block, ':', $pos);
            $drop = $c !== false && $c < $eol
                && lowerName(\substr($this->block, $pos, $c - $pos)) === $lower;
            if (!$drop) {
                $kept .= \substr($this->block, $pos, $eol + 2 - $pos);
            }
            $pos = $eol + 2;
        }
        $this->block = $kept;
    }
}

/**
 * One file received in a `multipart/form-data` upload: php's `$_FILES` row as an object.
 *
 * Get them from `Request::files()` or `Request::allFiles()`. The bytes were written to a temporary
 * file during parsing. Unless you claim it with `moveTo()`, the temporary file is deleted when the
 * request ends, whether the handler returned or threw. `tmpName` is empty unless `error` is
 * `ERR_OK`.
 *
 * @example doc_http_upload
 * @see Request
 */
// One uploaded file, php's $_FILES row as an object. `tmpName` is '' unless
// `error` is ERR_OK; the temp file is the request's and goes away with it
// unless {@see moveTo} claims it first.
final class UploadedFile
{
    /** The upload succeeded (php `UPLOAD_ERR_OK`). */
    public const ERR_OK = 0;
    /**
     * The file exceeded `Server::uploadMaxFilesize()` (php `UPLOAD_ERR_INI_SIZE`). No temporary file
     * is kept.
     */
    public const ERR_INI_SIZE = 1;
    /** The file exceeded the form's `MAX_FILE_SIZE` field (php `UPLOAD_ERR_FORM_SIZE`). */
    public const ERR_FORM_SIZE = 2;
    /** The body ended in the middle of the file (php `UPLOAD_ERR_PARTIAL`). */
    public const ERR_PARTIAL = 3;
    /** The part carried no file, for example `filename=""` (php `UPLOAD_ERR_NO_FILE`). */
    public const ERR_NO_FILE = 4;
    /** There was no temporary directory to write to (php `UPLOAD_ERR_NO_TMP_DIR`). */
    public const ERR_NO_TMP_DIR = 6;
    /** The temporary file could not be written (php `UPLOAD_ERR_CANT_WRITE`). */
    public const ERR_CANT_WRITE = 7;

    private bool $moved = false;

    /**
     * Describes one uploaded file. The server builds these while parsing; construct one yourself only
     * in tests.
     *
     * @param $field  The form field name (`name=` of the part).
     * @param $name  Base name of the file as the client sent it.
     * @param $fullPath  The client's file name exactly as sent (php 8.1's `full_path`).
     * @param $type  The part's `Content-Type`, as the client claimed it. Empty when `error` is not `ERR_OK`.
     * @param $size  Size in bytes; `0` when `error` is not `ERR_OK`.
     * @param $error  One of the `ERR_*` constants.
     * @param $tmpName  Path of the temporary file; empty when `error` is not `ERR_OK`.
     */
    public function __construct(
        public readonly string $field,
        /** basename of what the client sent */
        public readonly string $name,
        /** the client's filename as sent, php 8.1's `full_path` */
        public readonly string $fullPath,
        public readonly string $type,
        public readonly int $size,
        public readonly int $error,
        public readonly string $tmpName,
    ) {
    }

    /**
     * Whether the file arrived intact and has not been moved: `error` is `ERR_OK`, `tmpName` is set,
     * and `moveTo()` has not succeeded yet.
     *
     * @return bool  `true` if `moveTo()` and `contents()` will work.
     */
    public function isValid(): bool
    {
        return $this->error === self::ERR_OK && !$this->moved && $this->tmpName !== '';
    }

    /**
     * Moves the temporary file to its final place, so it survives the end of the request.
     *
     * Uses `rename()` and falls back to copy-and-delete across devices. After a successful move
     * `isValid()` is `false`. Does nothing and answers `false` for a file that is not valid.
     *
     * @param $dest  Destination path. An existing file is overwritten.
     * @return bool  `true` on success.
     */
    // rename(2), falling back to copy+unlink across devices.
    public function moveTo(string $dest): bool
    {
        if (!$this->isValid()) {
            return false;
        }
        $ok = @\rename($this->tmpName, $dest);
        if (!$ok) {
            $ok = @\copy($this->tmpName, $dest);
            if ($ok) {
                @\unlink($this->tmpName);
            }
        }
        if ($ok) {
            $this->moved = true;
            \Manticore\Sapi\uploadMoved($this->tmpName);
        }
        return $ok;
    }

    /**
     * Reads the whole file into memory.
     *
     * @return string  The bytes, or an empty string if the file is not valid.
     */
    public function contents(): string
    {
        if (!$this->isValid()) {
            return '';
        }
        $s = \file_get_contents($this->tmpName);
        return $s === false ? '' : $s;
    }
}

/**
 * multipart/form-data, RFC 7578, as a state machine over a chunk source so
 * the same code serves a buffered body and a streamed one. The only scan is
 * `strpos` for the delimiter, and bytes that could be the head of a split
 * delimiter stay in the buffer across reads.
 *
 * Two entry points over ONE set of state fields, differing only in who owns
 * the body bytes: {@see parseAll} (push) runs to the end, field parts to
 * strings and file parts to temp files; {@see parts} (pull) yields a
 * {@see Part} per part and hands its bytes to the consumer through
 * {@see readPart} — no temp file, nothing retained, a part the consumer stops
 * reading is drained before the next one is opened.
 *
 * @internal
 */
final class Multipart
{
    private const READ = 65536;
    private const ST_PREAMBLE = 0;
    private const ST_HEAD = 1;
    private const ST_BODY = 2;
    private const ST_DONE = 3;

    /** "\r\n--" . boundary */
    private string $delim;
    /** Closure(int $max): string */
    private mixed $source;
    private string $buf = '';
    private int $state = 0;
    private bool $eof = false;
    /** Pull mode ({@see parts}): no temp file is opened for a file part. */
    private bool $pull = false;
    /** Bumped per part head; a {@see Part} reads only while it is the current one. */
    private int $pSeq = 0;

    /** @var array<int|string, mixed> */
    private array $fields;
    /** @var array<int, UploadedFile> */
    private array $files = [];
    private int $fileCount = 0;
    private int $maxFiles;
    private int $maxSize;
    private int $fieldCount = 0;
    private int $maxInputVars;
    /** the MAX_FILE_SIZE field, php's per-form cap; 0 = none, as php's `max_file_size &&` */
    private int $formMax = 0;

    private string $pName = '';
    private string $pFile = '';
    private bool $pIsFile = false;
    private string $pType = '';
    private string $pValue = '';
    /** \Resource|null */
    private mixed $pTmp = null;
    private string $pTmpName = '';
    private int $pSize = 0;
    private int $pError = 0;

    public function __construct(string $contentType, mixed $source, int $maxFileUploads = 20, int $uploadMaxFilesize = 2097152, int $maxInputVars = 1000)
    {
        $this->delim = "\r\n--" . self::boundaryOf($contentType);
        $this->source = $source;
        $this->maxFiles = $maxFileUploads;
        $this->maxSize = $uploadMaxFilesize;
        $this->maxInputVars = $maxInputVars;
        $this->fields = \Manticore\Sapi\Context::$emptyGpc;
    }

    /** The boundary parameter, unquoted; '' when the header has none. */
    public static function boundaryOf(string $ct): string
    {
        $p = \stripos($ct, 'boundary=');
        if ($p === false) {
            return '';
        }
        $v = \substr($ct, $p + 9);
        $semi = \strpos($v, ';');
        if ($semi !== false) {
            $v = \substr($v, 0, $semi);
        }
        $v = \trim($v);
        if ($v !== '' && $v[0] === '"') {
            $v = \trim($v, '"');
        }
        return $v;
    }

    /** @return array<int|string, mixed> */
    public function fields(): array<int|string, mixed>
    {
        return $this->fields;
    }

    /** @return array<int, UploadedFile> */
    public function files(): array<int, UploadedFile>
    {
        return $this->files;
    }

    /**
     * Run to the closing delimiter. False = malformed (the server answers 400),
     * and then no temp file survives: the open part's and every collected one's
     * are unlinked and `files()` is empty, so a stream of bad bodies cannot
     * fill the disk.
     */
    public function parseAll(): bool
    {
        if (\strlen($this->delim) <= 4) {
            return false;
        }
        while ($this->state !== self::ST_DONE) {
            if ($this->state === self::ST_PREAMBLE) {
                if (!$this->preamble()) {
                    return $this->abort();
                }
            } elseif ($this->state === self::ST_HEAD) {
                if ($this->head() < 0) {
                    return $this->abort();
                }
            } elseif (!$this->body()) {
                return $this->abort();
            }
            if ($this->eof && $this->state !== self::ST_DONE) {
                // Cut off mid-part: php marks the open file PARTIAL — only a
                // file still being written; one already dropped (-1) or
                // failed (4, 1, 2) keeps its own verdict.
                if ($this->state === self::ST_BODY) {
                    if ($this->pIsFile && $this->pError === 0) {
                        $this->pError = UploadedFile::ERR_PARTIAL;
                    }
                    $this->closePart();
                }
                $this->state = self::ST_DONE;
            }
        }
        return true;
    }

    /**
     * Pull mode: one {@see Part} per part, in wire order. A part the consumer
     * did not read to its end is drained when the generator resumes, so the
     * next part always starts at its head. Malformed input throws — there is
     * no 400 to answer here, the handler is already running.
     *
     * @return \Generator<int, Part>
     */
    public function parts(): \Generator
    {
        $this->pull = true;
        if (\strlen($this->delim) <= 4 || !$this->preamble()) {
            throw new \RuntimeException('malformed multipart');
        }
        while ($this->state === self::ST_HEAD) {
            if ($this->head() < 0) {
                throw new \RuntimeException('malformed multipart');
            }
            yield new Part($this->pName, $this->pFile, $this->pType, $this->pSeq, $this);
            $this->skipRest();
        }
    }

    /**
     * Up to $max bytes of the current part ({@see body} bounded to $max and
     * stopping AT the delimiter); '' once the part's delimiter is reached, or
     * for a Part that is no longer the current one. At EOF the remainder is
     * the part — a body cut off mid-part ends it silently (no PARTIAL as the
     * push mode has); that remainder is at most `strlen(delim) - 1` bytes and
     * may exceed $max.
     */
    public function readPart(int $max, int $seq): string
    {
        if ($seq !== $this->pSeq || $this->state !== self::ST_BODY || $max <= 0) {
            return '';
        }
        while (true) {
            $p = \strpos($this->buf, $this->delim);
            if ($p !== false) {
                if ($p === 0) {
                    $this->endPart();
                    return '';
                }
                return $this->take($p < $max ? $p : $max);
            }
            $avail = \strlen($this->buf) - (\strlen($this->delim) - 1);
            if ($avail > 0) {
                return $this->take($avail < $max ? $avail : $max);
            }
            if (!$this->fill()) {
                $out = $this->buf;
                $this->buf = '';
                $this->state = self::ST_DONE;
                return $out;
            }
        }
    }

    private function take(int $n): string
    {
        $out = \substr($this->buf, 0, $n);
        $this->buf = \substr($this->buf, $n);
        return $out;
    }

    /** The delimiter is at the head of the buffer: step over it, then {@see afterDelim}. */
    private function endPart(): void
    {
        $this->buf = \substr($this->buf, \strlen($this->delim));
        if (!$this->afterDelim()) {
            $this->state = self::ST_DONE;
            throw new \RuntimeException('malformed multipart');
        }
    }

    /** Drain the current part to its delimiter — the consumer stopped early. */
    private function skipRest(): void
    {
        while ($this->state === self::ST_BODY) {
            $this->readPart(self::READ, $this->pSeq);
        }
    }

    /** Malformed: drop the open part's temp file and every collected one. */
    private function abort(): bool
    {
        $this->dropTmp();
        foreach ($this->files as $f) {
            if ($f->tmpName !== '') {
                @\unlink($f->tmpName);
            }
        }
        $this->files = [];
        $this->state = self::ST_DONE;
        return false;
    }

    private function fill(): bool
    {
        if ($this->eof) {
            return false;
        }
        $fn = $this->source;
        $chunk = $fn(self::READ);
        if ($chunk === '') {
            $this->eof = true;
            return false;
        }
        $this->buf = $this->buf . $chunk;
        return true;
    }

    /** Skip to the first delimiter. The first one has no leading CRLF. */
    private function preamble(): bool
    {
        $first = \substr($this->delim, 2);
        while (true) {
            $p = \strpos($this->buf, $first);
            if ($p !== false) {
                $this->buf = \substr($this->buf, $p + \strlen($first));
                return $this->afterDelim();
            }
            $keep = \strlen($first) - 1;
            if (\strlen($this->buf) > $keep) {
                $this->buf = \substr($this->buf, \strlen($this->buf) - $keep);
            }
            if (!$this->fill()) {
                return false;
            }
        }
    }

    /**
     * After a delimiter: `--` ends the message, CRLF opens a part, anything
     * else is garbage (false → 400). EOF here is a message cut right after a
     * delimiter: the part before it is complete, so it ends as DONE.
     */
    private function afterDelim(): bool
    {
        while (\strlen($this->buf) < 2) {
            if (!$this->fill()) {
                $this->state = self::ST_DONE;
                return true;
            }
        }
        if (\strncmp($this->buf, '--', 2) === 0) {
            $this->state = self::ST_DONE;
            return true;
        }
        if (\strncmp($this->buf, "\r\n", 2) !== 0) {
            return false;
        }
        $this->buf = \substr($this->buf, 2);
        $this->state = self::ST_HEAD;
        return true;
    }

    /** Part head: lines to the blank line. 0 = ok, -1 = malformed. */
    private function head(): int
    {
        while (true) {
            $end = \strpos($this->buf, "\r\n\r\n");
            if ($end !== false) {
                break;
            }
            if (\strlen($this->buf) > 16384 || !$this->fill()) {
                return -1;
            }
        }
        $lines = splitStr("\r\n", \substr($this->buf, 0, $end));
        $this->buf = \substr($this->buf, $end + 4);
        $this->pName = '';
        $this->pFile = '';
        $this->pIsFile = false;
        $this->pType = '';
        $this->pValue = '';
        $this->pSize = 0;
        $this->pError = 0;
        $this->pTmp = null;
        $this->pTmpName = '';
        foreach ($lines as $line) {
            $colon = \strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $hn = \strtolower(\trim(\substr($line, 0, $colon)));
            $hv = \trim(\substr($line, $colon + 1));
            if ($hn === 'content-disposition') {
                $this->pName = dispositionParam($hv, 'name');
                $this->pFile = dispositionParam($hv, 'filename');
                $this->pIsFile = dispositionParamPos($hv, 'filename') >= 0;
            } elseif ($hn === 'content-type') {
                $this->pType = $hv;
            }
        }
        if ($this->pName === '' && !$this->pIsFile) {
            // php (rfc1867): neither name= nor filename= is "Mime headers garbled";
            // a file part with only filename= is kept, field ''
            return -1;
        }
        $this->pSeq = $this->pSeq + 1;
        if ($this->pIsFile && !$this->pull) {
            $this->openFile();
        }
        $this->state = self::ST_BODY;
        return 0;
    }

    private function openFile(): void
    {
        if ($this->pFile === '') {
            $this->pError = UploadedFile::ERR_NO_FILE;
            return;
        }
        if ($this->fileCount >= $this->maxFiles) {
            // dropped silently, php's max_file_uploads
            $this->pError = -1;
            return;
        }
        $this->fileCount = $this->fileCount + 1;
        $tmp = \tempnam(\sys_get_temp_dir(), 'php');
        if ($tmp === false) {
            $this->pError = UploadedFile::ERR_NO_TMP_DIR;
            return;
        }
        $r = @\fopen($tmp, 'wb');
        if ($r === false) {
            @\unlink($tmp);
            $this->pError = UploadedFile::ERR_CANT_WRITE;
            return;
        }
        $this->pTmp = $r;
        $this->pTmpName = $tmp;
    }

    /**
     * Body bytes up to the next delimiter; everything before a possible split
     * delimiter is consumed. False = garbage after the delimiter.
     */
    private function body(): bool
    {
        while (true) {
            $p = \strpos($this->buf, $this->delim);
            if ($p !== false) {
                $this->consume(\substr($this->buf, 0, $p));
                $this->buf = \substr($this->buf, $p + \strlen($this->delim));
                $this->closePart();
                return $this->afterDelim();
            }
            $keep = \strlen($this->delim) - 1;
            $n = \strlen($this->buf);
            if ($n > $keep) {
                $this->consume(\substr($this->buf, 0, $n - $keep));
                $this->buf = \substr($this->buf, $n - $keep);
            }
            if (!$this->fill()) {
                $this->consume($this->buf);
                $this->buf = '';
                return true;
            }
        }
    }

    private function consume(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        if (!$this->pIsFile) {
            $this->pValue = $this->pValue . $bytes;
            return;
        }
        if ($this->pError !== 0) {
            return;
        }
        $this->pSize = $this->pSize + \strlen($bytes);
        if ($this->pSize > $this->maxSize || ($this->formMax > 0 && $this->pSize > $this->formMax)) {
            $this->pError = $this->pSize > $this->maxSize ? UploadedFile::ERR_INI_SIZE : UploadedFile::ERR_FORM_SIZE;
            $this->dropTmp();
            return;
        }
        $r = $this->pTmp;
        if ($r !== null) {
            \fwrite($r, $bytes);
        }
    }

    private function dropTmp(): void
    {
        $r = $this->pTmp;
        if ($r !== null) {
            \fclose($r);
            @\unlink($this->pTmpName);
        }
        $this->pTmp = null;
        $this->pTmpName = '';
        $this->pSize = 0;
    }

    private function closePart(): void
    {
        if (!$this->pIsFile) {
            // php's rfc1867.c compares this field name with `strcasecmp`, so
            // `max_file_size` and `Max_File_Size` arm the cap exactly as the
            // canonical spelling does.
            if (\strcasecmp($this->pName, 'MAX_FILE_SIZE') === 0 && \ctype_digit($this->pValue)) {
                $this->formMax = (int)$this->pValue;
            }
            if ($this->fieldCount >= $this->maxInputVars) {
                // dropped silently, php's max_input_vars applies to rfc1867 fields too
                return;
            }
            $this->fieldCount = $this->fieldCount + 1;
            nestedAssign($this->fields, $this->pName, $this->pValue, false);
            return;
        }
        if ($this->pError === -1) {
            // over max_file_uploads: not reported, as php
            return;
        }
        if ($this->pError === UploadedFile::ERR_PARTIAL) {
            $this->dropTmp();
        }
        $r = $this->pTmp;
        if ($r !== null) {
            \fclose($r);
            $this->pTmp = null;
        }
        $name = $this->pFile;
        $slash = \strrpos($name, '/');
        $bslash = \strrpos($name, '\\');
        $cut = $slash === false ? $bslash : ($bslash === false ? $slash : ($slash > $bslash ? $slash : $bslash));
        if ($cut !== false) {
            $name = \substr($name, $cut + 1);
        }
        $this->files[] = new UploadedFile(
            $this->pName,
            $name,
            $this->pFile,
            $this->pError === 0 ? $this->pType : '',
            $this->pError === 0 ? $this->pSize : 0,
            $this->pError,
            $this->pError === 0 ? $this->pTmpName : '',
        );
        if ($this->pError !== 0 && $this->pTmpName !== '') {
            @\unlink($this->pTmpName);
        }
    }
}

/**
 * One part of a streamed `multipart/form-data` body, read straight off the connection.
 *
 * Get parts from `Request::multipart()`. Nothing is buffered beyond a single read and no temporary
 * file is created. A part is readable only while it is the current one: once the loop moves on,
 * `read()` answers `''`. A part you stop reading is drained for you when the loop advances.
 *
 * @see Request
 * @see Server
 */
// One part of a streamed multipart body ({@see Request::multipart}): a bounded
// view over the parser's current part. Its bytes are read from the wire as the
// handler asks for them and are retained nowhere else; once the generator
// moves on, {@see read} answers ''.
final class Part
{
    /**
     * Created by `Request::multipart()`; not meant to be constructed by user code.
     *
     * @param $name  The `name=` of the part's `Content-Disposition`.
     * @param $filename  The `filename=`; empty for a plain field, and also for a file part sent with `filename=""`.
     * @param $type  The part's `Content-Type`, empty when it has none.
     * @param $seq  Internal sequence number.
     * @param $m  Internal parser.
     * @internal
     */
    public function __construct(
        /** The `name=` of the Content-Disposition. */
        public readonly string $name,
        /** The `filename=`; '' for a field — and for a file part sent with
         *  `filename=""`, indistinguishable from a field here (the buffered
         *  path reports it as `UPLOAD_ERR_NO_FILE`). */
        public readonly string $filename,
        /** The part's Content-Type, '' when it has none. */
        public readonly string $type,
        private int $seq,
        private Multipart $m,
    ) {
    }

    /**
     * Reads up to `$max` bytes of this part.
     *
     * Answers `''` once the part's closing delimiter is reached, or when this part is no longer the
     * current one. If the client cuts the connection mid-part, the remaining bytes are returned and
     * then `''`; there is no error signal in this mode.
     *
     * @param $max  Largest number of bytes to return.
     * @return string  The bytes, or an empty string at the end of the part.
     */
    // Up to $max bytes of this part; '' once its delimiter is reached.
    public function read(int $max): string
    {
        return $this->m->readPart($max, $this->seq);
    }

    /**
     * Reads the rest of this part into one string. The size is not capped; use `read()` for large
     * files.
     *
     * @return string  The remaining bytes.
     */
    // The rest of this part as one string — the consumer's memory, uncapped.
    public function readAll(): string
    {
        $out = '';
        while (($c = $this->read(65536)) !== '') {
            $out = $out . $c;
        }
        return $out;
    }
}

/**
 * One parsed HTTP request, as the handler receives it. Read-only.
 *
 * The public properties are `readonly`. Query and cookie parsing is lazy and cached: reading five
 * query parameters scans the query string once. The path is percent-decoded and `..`-collapsed (so
 * it can be used to look files up); `target` keeps the raw request-target for code that needs the
 * wire form.
 *
 * ```php
 * $server->serve(function (Request $req): Response {
 *     $name = $req->query('name', 'world');
 *     return (new Response())->text("hello $name\n");
 * });
 * ```
 *
 * The server refuses malformed requests before the handler runs (see `Server`), so a handler only
 * ever sees a request that is well-formed HTTP/1.0 or 1.1.
 *
 * @example doc_http_request
 * @see Response
 * @see Server
 * @see Headers
 */
// One parsed request. Immutable to the handler.
//
// The public surface is `readonly`; the two memo fields and the bitfield beside
// them are not, and that is deliberate rather than a loophole. PHP freezes only
// the properties declared `readonly`, so nothing a handler can reach changes —
// but a request whose query is scanned afresh for every `query()` call scans it
// five times for a handler reading five parameters. `$parsed` keeps the
// already-done test to a single load.
final class Request
{
    private const P_QUERY = 1;
    private const P_COOKIE = 2;
    private const P_QUERY_NESTED = 4;
    private const P_POST = 8;

    /** @var array<string,string> */
    private array<string, string> $queryCache = [];
    /** @var array<string,string> */
    private array<string, string> $cookieCache = [];
    /** @var array<int|string, mixed> */
    private array<int|string, mixed> $queryNested = [];
    /** @var array<int|string, mixed> */
    private array<int|string, mixed> $postNested = [];
    private int $parsed = 0;

    private ?Multipart $multipart = null;
    private bool $multipartFailed = false;
    /** {@see multipart} ran: the streamed body is that generator's. */
    private bool $multipartUsed = false;
    private int $maxFileUploads = 20;
    private int $uploadMaxFilesize = 2097152;

    /** @var array<int, UploadedFile> */
    private static array<int, UploadedFile> $noFiles = [];

    /**
     * Creates a request value. The server builds these while parsing; construct one yourself only in
     * tests.
     *
     * @param $method  The raw method token, for example `GET` or `PROPFIND`.
     * @param $target  The request-target exactly as it arrived, still percent-encoded.
     * @param $path  The decoded, `..`-collapsed path. `%2F` is a separator here; read `$target` when the difference matters.
     * @param $queryString  The raw query string, without the leading `?`.
     * @param $version  `1.1` or `1.0`.
     * @param $headers  The request header fields.
     * @param $bodyRaw  The buffered body; empty when the body is streamed.
     * @param $streamed  Whether the body was left on the wire for `stream()` instead of buffered.
     * @param $remoteAddr  The client address as `ip:port`. Behind a trusted proxy this is the address the proxy reported (no port).
     * @param $secure  Whether the connection is TLS (or a trusted proxy reported `https`).
     * @param $peerAddr  The socket's own peer address, always. `remoteAddr` may come from a proxy header, this never does.
     * @param $forwardedPort  `X-Forwarded-Port` from a trusted proxy, otherwise an empty string.
     * @param $maxInputVars  php's `max_input_vars`: pairs beyond this many are dropped by `queryArray()` and `postArray()`.
     * @param $maxFileUploads  php's `max_file_uploads`: file parts beyond this many are dropped.
     * @param $uploadMaxFilesize  php's `upload_max_filesize` in bytes: a larger file part is kept with `ERR_INI_SIZE` and no temporary file.
     * @param $reader  The body reader, present only for a streamed body.
     */
    public function __construct(
        /** The raw method token — `GET`, but also `PROPFIND`. */
        public readonly string $method,
        /** The request-target exactly as it arrived, still percent-encoded. */
        public readonly string $target,
        /** Decoded and `..`-collapsed path. `%2F` IS a separator here — read
         *  {@see $target} when that distinction matters. */
        public readonly string $path,
        /** Raw query string, no leading `?`, still percent-encoded. */
        public readonly string $queryString,
        /** `1.1` or `1.0`. */
        public readonly string $version,
        public readonly Headers $headers,
        /** The body, when it was small enough to buffer; '' when streamed. */
        public readonly string $bodyRaw,
        /** True when the body was left on the wire for {@see stream}. */
        public readonly bool $streamed,
        public readonly string $remoteAddr,
        /** True when the connection is TLS. */
        public readonly bool $secure,
        /** The socket's own peer address, always — {@see $remoteAddr} may come
         *  from a trusted proxy's header. */
        public readonly string $peerAddr = '',
        /** `X-Forwarded-Port` from a trusted proxy, else '' — RFC 7239 has no
         *  port field of its own; the port inside a `Forwarded: for=` value
         *  is part of the client address and is discarded. */
        public readonly string $forwardedPort = '',
        /** php's `max_input_vars`: pairs past this many are dropped by
         *  {@see queryArray} and {@see postArray}. */
        public readonly int $maxInputVars = 1000,
        int $maxFileUploads = 20,
        int $uploadMaxFilesize = 2097152,
        /** Present only for a streamed body. */
        private ?\Buffer\Reader $reader = null,
    ) {
        $this->maxFileUploads = $maxFileUploads;
        $this->uploadMaxFilesize = $uploadMaxFilesize;
    }

    /**
     * A request header value. The name is case-insensitive.
     *
     * A field sent more than once comes back comma-joined.
     *
     * @param $n  Header name.
     * @param $d  Returned when the header is absent.
     * @return string  The value, or `$d`.
     */
    public function header(string $n, string $d = ''): string
    {
        return $this->headers->get($n, $d);
    }

    /**
     * One query-string parameter, decoded (`+` is a space).
     *
     * The query is flat and last-wins: `?a[]=1` is the key `a[]`, and for a repeated key the last
     * value is returned. Use `queryArray()` for php's nested `$_GET` shape.
     *
     * @param $k  Parameter name.
     * @param $d  Returned when the parameter is absent.
     * @return string  The value, or `$d`.
     */
    public function query(string $k, string $d = ''): string
    {
        if (($this->parsed & self::P_QUERY) === 0) {
            $this->queryCache = parseQuery($this->queryString);
            $this->parsed = $this->parsed | self::P_QUERY;
        }
        if (!isset($this->queryCache[$k])) {
            return $d;
        }
        return $this->queryCache[$k];
    }

    /**
     * All query-string parameters as a flat, last-wins map of decoded names to decoded values.
     *
     * @return array<string,string> flat, last-wins
     */
    public function queries(): array<string, string>
    {
        if (($this->parsed & self::P_QUERY) === 0) {
            $this->queryCache = parseQuery($this->queryString);
            $this->parsed = $this->parsed | self::P_QUERY;
        }
        return $this->queryCache;
    }

    /**
     * The query string in php's `$_GET` shape.
     *
     * Names nest exactly like `parse_str()`: `a[]=1&b[x]=2` gives `['a' => ['1'], 'b' => ['x' =>
     * '2']]`, `.` and spaces in a name become `_`, and a canonical decimal key becomes an `int`. At
     * most `max_input_vars` pairs (default 1000, `Server::maxInputVars()`) are read; the rest are
     * dropped silently.
     *
     * @return array<int|string, mixed>  The nested array.
     */
    // php's $_GET shape: nested (`a[]`, `a[b][c]`), last-wins, max_input_vars-capped.
    public function queryArray(): array<int|string, mixed>
    {
        if (($this->parsed & self::P_QUERY_NESTED) === 0) {
            $this->queryNested = parseQueryNested($this->queryString, $this->maxInputVars);
            $this->parsed = $this->parsed | self::P_QUERY_NESTED;
        }
        return $this->queryNested;
    }

    /**
     * The form fields of the body in php's `$_POST` shape.
     *
     * Filled from an `application/x-www-form-urlencoded` body, or from the fields of a buffered
     * `multipart/form-data` body (a multipart body is parsed on the first call). Any other content
     * type gives an empty array. Names nest like `queryArray()` and the same `max_input_vars` cap
     * applies.
     *
     * @return array<int|string, mixed>  The nested array.
     * @throws \LogicException  when the body is a streamed multipart body; read it with `multipart()`
     */
    // php's $_POST shape from a urlencoded form, or a multipart body's fields.
    public function postArray(): array<int|string, mixed>
    {
        if (($this->parsed & self::P_POST) === 0) {
            if (\strncasecmp($this->contentType(), 'multipart/form-data', 19) === 0) {
                $this->ensureMultipart('postArray');
                $m = $this->multipart;
                $this->postNested = $m === null ? \Manticore\Sapi\Context::$emptyGpc : $m->fields();
            } else {
                $this->postNested = $this->contentType() === 'application/x-www-form-urlencoded'
                    ? parseQueryNested($this->bodyRaw, $this->maxInputVars)
                    : \Manticore\Sapi\Context::$emptyGpc;
            }
            $this->parsed = $this->parsed | self::P_POST;
        }
        return $this->postNested;
    }

    /**
     * Parse a buffered multipart body once. A streamed one is the handler's
     * ({@see multipart}); asking for it whole here is a LogicException.
     */
    private function ensureMultipart(string $who): void
    {
        if ($this->multipart !== null || $this->multipartFailed) {
            return;
        }
        if (\strncasecmp($this->contentType(), 'multipart/form-data', 19) !== 0) {
            return;
        }
        if ($this->streamed) {
            throw new \LogicException('Http\\Request::' . $who . '(): body is streamed — use multipart()');
        }
        $m = new Multipart($this->header('Content-Type'), stringSource($this->bodyRaw), $this->maxFileUploads, $this->uploadMaxFilesize, $this->maxInputVars);
        if (!$m->parseAll()) {
            $this->multipartFailed = true;
            return;
        }
        $this->multipart = $m;
        // Registered for the request-end sweep: a temp file the handler never
        // moves is unlinked when the request ends, thrown or not.
        foreach ($m->files() as $f) {
            \Manticore\Sapi\uploadRegister($f->tmpName);
        }
    }

    /**
     * Whether a buffered `multipart/form-data` body could not be parsed.
     *
     * The server answers such a request with `400` before the handler runs, so a handler normally sees
     * `false`. Always `false` for a streamed body and for other content types.
     *
     * @return bool  `true` if the body is multipart and malformed.
     */
    public function multipartFailed(): bool
    {
        if ($this->streamed) {
            return false;
        }
        $this->ensureMultipart('multipartFailed');
        return $this->multipartFailed;
    }

    /**
     * The parts of a streamed `multipart/form-data` body, one at a time.
     *
     * Only available when the server streams bodies (`Server::streamBodies()`) and the body is larger
     * than `maxBodySize`. Each `Part` is read from the connection as the handler consumes it: no
     * temporary file, nothing buffered beyond one read. The generator owns the body, so call this once
     * and do not mix it with `allFiles()` or `postArray()`.
     *
     * The generator is lazy: a malformed body throws `RuntimeException('malformed multipart')` while
     * you iterate, not when you call `multipart()`. If the handler lets it escape, the server answers
     * `500` (or calls `onError`).
     *
     * @return \Generator<int, Part>  The parts in wire order.
     * @throws \LogicException  on first iteration, when the body is buffered (use `allFiles()`) or was already consumed
     * @see Part
     */
    // The parts of a STREAMED multipart body ({@see Server::streamBodies}),
    // one {@see Part} at a time, read from the wire as the handler consumes
    // them — no temp file, nothing buffered beyond one read. The body is the
    // generator's: {@see allFiles}/{@see postArray} refuse a streamed
    // multipart body, and a buffered one is theirs (this throws).
    public function multipart(): \Generator
    {
        if (!$this->streamed) {
            throw new \LogicException('Http\\Request::multipart(): body is buffered — use allFiles()');
        }
        if ($this->multipart !== null || $this->multipartUsed) {
            throw new \LogicException('Http\\Request::multipart(): body already consumed');
        }
        $this->multipartUsed = true;
        $rd = $this->reader;
        if ($rd !== null) {
            $m = new Multipart($this->header('Content-Type'), function (int $max) use ($rd): string {
                return $rd->read($max);
            }, $this->maxFileUploads, $this->uploadMaxFilesize, $this->maxInputVars);
            yield from $m->parts();
        }
    }

    /**
     * Every uploaded file of a buffered `multipart/form-data` body, in wire order, including `f[]`
     * names.
     *
     * The body is parsed on the first call. Each file was written to a temporary file that is deleted
     * when the request ends unless you call `UploadedFile::moveTo()`. At most `max_file_uploads` files
     * (default 20, `Server::maxFileUploads()`) are kept. Without a multipart body the result is empty.
     *
     * @return array<int, UploadedFile>  The files in wire order.
     * @throws \LogicException  when the body is a streamed multipart body; read it with `multipart()`
     * @see UploadedFile
     */
    // Every file part, in wire order.
    public function allFiles(): array<int, UploadedFile>
    {
        $this->ensureMultipart('allFiles');
        $m = $this->multipart;
        return $m === null ? self::$noFiles : $m->files();
    }

    /**
     * The first uploaded file for each field name, keyed by field. Use `allFiles()` when a field can
     * carry several files.
     *
     * @return array<string, UploadedFile>  Field name to file.
     * @throws \LogicException  when the body is a streamed multipart body
     */
    // The first file per field name.
    public function files(): array<string, UploadedFile>
    {
        $out = [];
        foreach ($this->allFiles() as $f) {
            if (!isset($out[$f->field])) {
                $out[$f->field] = $f;
            }
        }
        return $out;
    }

    /**
     * The uploads in php's `$_FILES` shape: six columns (`name`, `full_path`, `type`, `tmp_name`,
     * `error`, `size`), with nested names such as `f[]` or `u[avatar]` transposed per column. A part
     * with a `filename=` but no `name=` is filed under a running integer key, as php does.
     *
     * @return array<int|string, mixed>  The nested array.
     * @throws \LogicException  when the body is a streamed multipart body
     */
    // php's $_FILES: six columns, nested names transposed per column
    // (`f[]`×2 → `$_FILES['f']['name'] = [0 => …, 1 => …]`, `u[avatar]` →
    // `$_FILES['u']['name']['avatar']`). A part with filename= but no name= is
    // php's anonymous upload: rfc1867 files it under a running int, `$_FILES[0]`.
    public function filesArray(): array<int|string, mixed>
    {
        $out = \Manticore\Sapi\Context::$emptyGpc;
        $anon = 0;
        foreach ($this->allFiles() as $f) {
            $field = $f->field;
            if ($field === '') {
                $field = (string)$anon;
                $anon = $anon + 1;
            }
            $this->filesColumn($out, $field, 'name', $f->name);
            $this->filesColumn($out, $field, 'full_path', $f->fullPath);
            $this->filesColumn($out, $field, 'type', $f->type);
            $this->filesColumn($out, $field, 'tmp_name', $f->tmpName);
            $this->filesColumn($out, $field, 'error', $f->error);
            $this->filesColumn($out, $field, 'size', $f->size);
        }
        return $out;
    }

    /**
     * `f[a][]` with column `size` → `$out['f']['size']['a'][]`: the base is the
     * field, the column sits between the base and the bracket path. No urldecode:
     * a multipart name is registered as sent (rfc1867), unlike a query key.
     * @param array<int|string, mixed> $out
     */
    private function filesColumn(array &$out, string $field, string $col, mixed $val): void
    {
        $b = \strpos($field, '[');
        if ($b === false) {
            $bk = canonicalIntKey($field) ? (int)$field : $field;
            if (!isset($out[$bk]) || !\is_array($out[$bk])) {
                $out[$bk] = \Manticore\Sapi\Context::$emptyGpc;
            }
            $row = $out[$bk];
            $row[$col] = $val;
            $out[$bk] = $row;
            return;
        }
        nestedAssign($out, \substr($field, 0, $b) . '[' . $col . ']' . \substr($field, $b), $val, false);
    }

    /**
     * One cookie from the `Cookie` header, urldecoded.
     *
     * Last-wins for a repeated name.
     *
     * @param $k  Cookie name.
     * @param $d  Returned when the cookie is absent.
     * @return string  The value, or `$d`.
     */
    public function cookie(string $k, string $d = ''): string
    {
        if (($this->parsed & self::P_COOKIE) === 0) {
            $this->cookieCache = parseCookies($this->headers->get('cookie'));
            $this->parsed = $this->parsed | self::P_COOKIE;
        }
        if (!isset($this->cookieCache[$k])) {
            return $d;
        }
        return $this->cookieCache[$k];
    }

    /**
     * All cookies of the `Cookie` header as a map of names to urldecoded values, last-wins.
     *
     * @return array<string,string>  Cookie name to value.
     */
    public function cookies(): array<string, string>
    {
        if (($this->parsed & self::P_COOKIE) === 0) {
            $this->cookieCache = parseCookies($this->headers->get('cookie'));
            $this->parsed = $this->parsed | self::P_COOKIE;
        }
        return $this->cookieCache;
    }

    /**
     * The request body as a string.
     *
     * Bodies up to `Server::maxBodySize()` (8 MiB by default) are buffered here. A chunked body is
     * always buffered. For a streamed body this is an empty string; read `stream()` instead.
     *
     * @return string  The body bytes.
     * @example doc_http_json
     */
    // The buffered body. '' for a streamed one — use {@see stream}.
    public function body(): string
    {
        return $this->bodyRaw;
    }

    /**
     * The body reader for a streamed body, otherwise `null`.
     *
     * The server streams a body only when `Server::streamBodies(true)` is on and the declared
     * `Content-Length` exceeds `maxBodySize`; the reader's budget is that length. A body the handler
     * does not read is drained before the connection is reused.
     *
     * @return ?\Buffer\Reader  A reader, or `null` when the body was buffered.
     */
    // The body reader for a streamed body, null when it was buffered.
    public function stream(): ?\Buffer\Reader
    {
        return $this->reader;
    }

    /**
     * Whether the request carries a body (buffered and non-empty, or streamed).
     *
     * @return bool  `true` if there is a body to read.
     */
    public function hasBody(): bool
    {
        return $this->streamed || $this->bodyRaw !== '';
    }

    /**
     * The declared `Content-Length`, or -1 when the request did not declare one
     * (a chunked body never does — its size is only known once it is read).
     *
     * @return int  The declared length in bytes, or `-1`.
     */
    public function contentLength(): int
    {
        $v = $this->headers->get('content-length');
        if ($v === '') {
            return -1;
        }
        return contentLength($v);
    }

    /**
     * The media type without its parameters, lowercased.
     *
     * @return string  For example `application/json`; empty when there is no `Content-Type`.
     */
    public function contentType(): string
    {
        $v = $this->headers->get('content-type');
        $s = \strpos($v, ';');
        if ($s !== false) {
            $v = \substr($v, 0, $s);
        }
        return \strtolower(\trim($v));
    }

    /**
     * The method as a {@see Method}, or null for one outside RFC 9110.
     *
     * @return ?Method  The matching `Method` case, or `null` for a token such as `PROPFIND`. Matching is case-sensitive.
     */
    public function methodEnum(): ?Method
    {
        return Method::tryFrom($this->method);
    }

    /**
     * Whether the request method is this one. Compared to the raw token, so it is case-sensitive.
     *
     * @param $m  The method to test for.
     * @return bool  `true` on a match.
     */
    public function is(Method $m): bool
    {
        return $this->method === $m->value;
    }

    /**
     * Whether the client allows the connection to be reused after this message.
     *
     * HTTP/1.1 is persistent unless it sends `Connection: close`; HTTP/1.0 is not unless it sends
     * `Connection: keep-alive`. The server also closes for other reasons (`Response::close()`,
     * `Server::keepAliveMax()`, shutdown), so this is the client's wish only.
     *
     * @return bool  `true` if the client wants to keep the connection open.
     */
    // Whether the connection may be reused after this message.
    public function isKeepAlive(): bool
    {
        return !connClose($this->headers->get('connection'), $this->version);
    }
}

/**
 * A response under construction: the value a handler returns.
 *
 * Mutable and fluent: every setter returns the same `Response`, so calls chain. A new response is
 * `200 OK` with no body. The server fills in what the handler left out: `Content-Length` (or
 * chunked framing for a stream), `Date`, `Server`, and `Connection` when the connection will
 * close. `HEAD` requests get the headers of the matching `GET` and no body.
 *
 * ```php
 * return (new Response(201))
 *     ->type('application/json')
 *     ->body(json_encode(['id' => 7]));
 * ```
 *
 * There is no `json()` helper: encode with `json_encode()` and set the type yourself, so only
 * programs that serialize pay for the encoder.
 *
 * A response has one body kind at a time. `text()`, `html()`, `body()`, `write()`, `stream()` and
 * `file()` replace each other (`write()` appends to a string body). php's own `header()`,
 * `setcookie()`, `http_response_code()` and `echo` also work inside a handler; the explicit
 * `Response` wins when both are used (see `Server::captureEcho()`).
 *
 * @example doc_http_response
 * @example doc_http_ambient
 * @see Request
 * @see Server
 * @see Status
 */
// A response under construction: mutable, fluent, and the handler's return value.
//
// Mutable rather than `with*()`-immutable because a handler builds exactly one
// of these and throws it over the wall. PSR-7's immutability buys substitution
// across middleware — and middleware is explicitly not in this layer; a package
// that wants PSR-7 wraps this.
//
// Every setter answers `Response`, not `static`: the class is `final`.
final class Response
{
    /**
     * The status code, `200` by default.
     *
     * Set it through `status()` or the constructor. Assigning the property directly works for reading
     * and for tests, but does not mark the status as chosen by the handler, so an ambient
     * `http_response_code()` call can still replace it.
     */
    public int $status = 200;

    /** The header block. The OBJECT is fixed; its contents are meant to change. */
    public readonly Headers $headers;

    private string $body = '';

    /** A `Closure(\Buffer\Writer): void` when the body is streamed.
     *  `mixed`, because PHP has no `callable` PROPERTY type. */
    private mixed $bodyFn = null;

    /** Whether the handler set a status itself — what lets an ambient
     *  `http_response_code()` win only when the Response stayed silent. */
    private bool $statusSet = false;

    /** A file body ({@see file}): the bytes never enter this object. The
     *  Resource stays OPEN until {@see Server::writeFile} has sent it. */
    private string $filePath = '';
    private ?\Resource $fileRes = null;
    private int $fileSize = 0;
    private int $fileMtime = 0;
    private int $fileOff = 0;
    private int $fileLen = 0;

    private bool $close = false;

    /** A `Closure(\Resource, \Buffer\ByteBuffer, Server): void` that owns the
     *  socket after a 101 ({@see takeover}). */
    private ?\Closure $takeoverFn = null;

    /**
     * Creates a response.
     *
     * @param $status  Status code; see `Status` for named constants.
     * @param $body  Initial body. Content-Type is not set by this; use `text()`, `html()` or `type()`.
     */
    public function __construct(int $status = 200, string $body = '')
    {
        $this->headers = new Headers();
        if ($status !== 200) {
            $this->status = $status;
            $this->statusSet = true;
        }
        $this->body = $body;
    }

    /**
     * Sets the status code.
     *
     * @param $c  Status code, for example `Status::NOT_FOUND`.
     * @return Response  This response.
     */
    public function status(int $c): Response
    {
        $this->status = $c;
        $this->statusSet = true;
        return $this;
    }

    /**
     * Sets a header, replacing every existing line with that name.
     *
     * @param $n  Header name.
     * @param $v  Header value.
     * @return Response  This response.
     */
    // Replace this header.
    public function header(string $n, string $v): Response
    {
        $this->headers->set($n, $v);
        return $this;
    }

    /**
     * Adds a header line and keeps any already set, so the field can repeat on the wire (for example
     * `Set-Cookie`).
     *
     * @param $n  Header name.
     * @param $v  Header value.
     * @return Response  This response.
     */
    // Append a header, keeping any already set.
    public function addHeader(string $n, string $v): Response
    {
        $this->headers->add($n, $v);
        return $this;
    }

    /**
     * Removes every line with this header name. An absent name is not an error.
     *
     * @param $n  Header name.
     * @return Response  This response.
     */
    public function withoutHeader(string $n): Response
    {
        $this->headers->remove($n);
        return $this;
    }

    /**
     * Sets the `Content-Type` header. Shorthand for `header('Content-Type', $ct)`.
     *
     * @param $ct  Media type, with parameters if needed, for example `application/json` or `text/csv; charset=utf-8`.
     * @return Response  This response.
     */
    public function type(string $ct): Response
    {
        $this->headers->set('Content-Type', $ct);
        return $this;
    }

    /**
     * Sets a plain-text body and the type `text/plain; charset=utf-8`.
     *
     * @param $s  The body.
     * @return Response  This response.
     */
    public function text(string $s): Response
    {
        $this->dropFile();
        $this->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $this->body = $s;
        return $this;
    }

    /**
     * Sets an HTML body and the type `text/html; charset=utf-8`.
     *
     * @param $s  The body.
     * @return Response  This response.
     */
    public function html(string $s): Response
    {
        $this->dropFile();
        $this->headers->set('Content-Type', 'text/html; charset=utf-8');
        $this->body = $s;
        return $this;
    }

    /**
     * Sets the body without touching the `Content-Type`.
     *
     * Combine with `type()`.
     *
     * @param $b  The body bytes.
     * @return Response  This response.
     * @example doc_http_json
     */
    public function body(string $b): Response
    {
        $this->dropFile();
        $this->body = $b;
        return $this;
    }

    /**
     * Appends to the body. Appending is amortized: repeated calls do not copy the body each time.
     *
     * @param $b  Bytes to append.
     * @return Response  This response.
     */
    // Append to the body — the in-place amortized `.=`, not a fresh string.
    public function write(string $b): Response
    {
        $this->dropFile();
        $this->body .= $b;
        return $this;
    }

    /**
     * Produces the body from a closure while it is being sent.
     *
     * The closure receives an `Http\ChunkedWriter`. There is no `Content-Length`: an HTTP/1.1 client
     * gets `Transfer-Encoding: chunked`, an HTTP/1.0 client gets the raw bytes and the end of the
     * connection ends the body. Call `flush()` on the writer to push bytes to the client; otherwise
     * they are batched. If the closure throws, the head has already been sent, so the server ends the
     * framing and drops the connection instead of answering `500`. Streamed bodies are never
     * compressed, and `HEAD` requests do not run the closure.
     *
     * ```php
     * return (new Response())->type('text/plain')->stream(function (Http\ChunkedWriter $w) {
     *     $w->write("line\n");
     *     $w->flush();
     * });
     * ```
     *
     * @param $fn  A `Closure(ChunkedWriter): void`.
     * @return Response  This response.
     * @example doc_http_stream
     * @see ChunkedWriter
     */
    // Produce the body from a closure taking a {@see \Buffer\Writer}.
    //
    // The response then goes out chunked: the length is not knowable before the
    // closure has run, and buffering it to find out is the thing streaming
    // exists to avoid.
    public function stream(callable $fn): Response
    {
        $this->dropFile();
        $this->bodyFn = $fn;
        return $this;
    }

    /**
     * Sends a file from disk.
     *
     * The length on the wire is the size of the file that was opened, so a file replaced during the
     * request cannot produce a mismatched length. On a plain TCP connection the bytes go out with
     * `sendfile(2)` and never enter the process; over TLS they are read and written in 64 KiB pieces.
     * The head gets `Content-Type` (from the extension unless set), `Last-Modified`, a weak `ETag` and
     * `Accept-Ranges: bytes`, each only if you have not set it yourself.
     *
     * For a response left at `200` to a `GET` or `HEAD`, the server answers `If-None-Match` and
     * `If-Modified-Since` with `304` and a single `Range: bytes=a-b` with `206` (`416` if
     * unsatisfiable). A response with another status is sent as is. With `Server::compression()` on, a
     * `<path>.gz` file that is not older than the original is sent in its place when the client
     * accepts gzip; a file is never compressed on the fly.
     *
     * A missing, unreadable or non-regular path is a bug in the handler, not a `404`: it throws. Turn
     * a request path into a safe file path with `safePath()`.
     *
     * @param $path  Path of a regular file.
     * @param $type  Content-Type to use; `null` picks one with `mimeFor()`.
     * @return Response  This response.
     * @throws \RuntimeException  when the path cannot be opened or is not a regular file
     * @example doc_http_file
     * @see Request
     */
    // Send a file from disk. The head is built from ONE `fstat` of the OPENED
    // fd, so the length on the wire is the length of the file the body will
    // read — a path stat'ed and then opened is two different files under a
    // concurrent write.
    //
    // A missing or unreadable path is the HANDLER's bug and throws here, not on
    // the wire; {@see safePath} is the one that answers null for a request path
    // that names nothing.
    public function file(string $path, ?string $type = null): Response
    {
        $r = @\fopen($path, 'rb');
        if ($r === false) {
            throw new \RuntimeException('Http\\Response::file: cannot open ' . $path);
        }
        $st = \fstat($r);
        if ($st === false || ($st['mode'] & 0170000) !== 0100000) {
            \fclose($r);
            throw new \RuntimeException('Http\\Response::file: not a regular file ' . $path);
        }
        $this->dropFile();
        $this->body = '';
        $this->bodyFn = null;
        $this->filePath = $path;
        $this->fileRes = $r;
        $this->fileSize = (int)$st['size'];
        $this->fileMtime = (int)$st['mtime'];
        $this->fileOff = 0;
        $this->fileLen = $this->fileSize;
        $h = $this->headers;
        if (!$h->has('content-type')) {
            $h->add('Content-Type', $type ?? mimeFor($path));
        } elseif ($type !== null) {
            $h->set('Content-Type', $type);
        }
        if (!$h->has('last-modified')) {
            $h->add('Last-Modified', httpDate($this->fileMtime));
        }
        if (!$h->has('etag')) {
            $h->add('ETag', $this->fileEtag());
        }
        if (!$h->has('accept-ranges')) {
            $h->add('Accept-Ranges', 'bytes');
        }
        return $this;
    }

    /**
     * Whether the body is a file set with `file()`.
     *
     * @return bool  `true` for a file body.
     */
    public function isFile(): bool { return $this->fileRes !== null; }
    /** @internal */
    public function fileResource(): ?\Resource { return $this->fileRes; }
    /**
     * The path given to `file()`, or an empty string.
     *
     * @return string  The path.
     */
    public function filePath(): string { return $this->filePath; }
    /**
     * The size in bytes of the file opened by `file()`, or `0`.
     *
     * @return int  The size.
     */
    public function fileSize(): int { return $this->fileSize; }
    /**
     * The modification time (Unix timestamp) of the file opened by `file()`, or `0`.
     *
     * @return int  The timestamp.
     */
    public function fileMtime(): int { return $this->fileMtime; }
    /** @internal */
    public function fileOffset(): int { return $this->fileOff; }
    /** @internal */
    public function fileLength(): int { return $this->fileLen; }

    /**
     * The weak `ETag` for the file: `W/"<mtime-hex>-<size-hex>"`. It is weak because a byte-identical
     * copy on another host has another mtime.
     *
     * @return string  The tag, including the quotes and the `W/` prefix.
     */
    // WEAK: a byte-identical copy on another host carries another mtime.
    public function fileEtag(): string
    {
        return 'W/"' . \dechex($this->fileMtime) . '-' . \dechex($this->fileSize) . '"';
    }

    /** @internal the byte window {@see Server::conditional} settles for a Range. */
    public function setFileWindow(int $off, int $len): void
    {
        $this->fileOff = $off;
        $this->fileLen = $len;
    }

    /**
     * Serve `<path>.gz` in place of the file when it exists and is not OLDER
     * than it — a stale sibling is a build artefact nobody refreshed, and
     * sending it would serve yesterday's asset for ever.
     *
     * `fileMtime` deliberately stays the ORIGINAL's, so `Last-Modified` and
     * `If-Modified-Since` keep meaning the source file; only the ETag gains a
     * `-gz` suffix, because the two representations are not byte-identical.
     *
     * @internal
     */
    public function useGzSibling(): void
    {
        if ($this->fileRes === null) {
            return;
        }
        $r = @\fopen($this->filePath . '.gz', 'rb');
        if ($r === false) {
            return;
        }
        $st = \fstat($r);
        if ($st === false || (int)$st['mtime'] < $this->fileMtime) {
            \fclose($r);
            return;
        }
        \fclose($this->fileRes);
        $this->fileRes = $r;
        $this->fileSize = (int)$st['size'];
        $this->fileOff = 0;
        $this->fileLen = $this->fileSize;
        $this->headers->set('Content-Encoding', 'gzip');
        $this->headers->set('ETag', 'W/"' . \dechex($this->fileMtime) . '-' . \dechex($this->fileSize) . '-gz"');
    }

    /** A body of another kind replaces a file — and closes its fd. */
    private function dropFile(): void
    {
        if ($this->fileRes !== null) {
            \fclose($this->fileRes);
            $this->fileRes = null;
            $this->filePath = '';
            $this->fileSize = 0;
            $this->fileMtime = 0;
            $this->fileOff = 0;
            $this->fileLen = 0;
        }
    }

    /**
     * Sets a `Location` header and the status. There is no body.
     *
     * @param $loc  Target URL or path.
     * @param $code  Redirect status: 301, 302, 303, 307 or 308.
     * @return Response  This response.
     */
    public function redirect(string $loc, int $code = 302): Response
    {
        $this->headers->set('Location', $loc);
        return $this->status($code);
    }

    /**
     * Queues a `Set-Cookie` header.
     *
     * Rendered by the same code as php's `setcookie()`, with the same attribute order. The value is
     * percent-encoded. Safe defaults: `HttpOnly`, `SameSite=Lax`, path `/`. Several cookies, and
     * cookies from `setcookie()`, all reach the client as separate lines.
     *
     * @param $n  Cookie name.
     * @param $v  Cookie value. An empty string deletes the cookie: an already expired `deleted` cookie is sent.
     * @param $expires  Expiry as a Unix timestamp; `0` makes a session cookie.
     * @param $path  The `Path` attribute.
     * @param $domain  The `Domain` attribute; empty omits it.
     * @param $secure  Send only over HTTPS.
     * @param $httponly  Hide from client-side scripts. On by default.
     * @param $sameSite  The `SameSite` value, normally `Lax`, `Strict` or `None`; empty omits the attribute.
     * @return Response  This response.
     */
    // Queue a `Set-Cookie`, rendered by the same function `setcookie()` uses —
    // one renderer, php's attribute order. The value is percent-encoded, as
    // `setcookie()` encodes it.
    public function cookie(
        string $n,
        string $v,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httponly = true,
        string $sameSite = 'Lax',
    ): Response {
        $enc = $v === '' ? '' : \rawurlencode($v);
        $line = \Manticore\Sapi\cookieLine($n, $enc, $expires, $path, $domain, $secure, $httponly, $sameSite);
        // Manticore\Sapi\cookieLine answers the whole wire line, prefix included.
        $c = \strpos($line, ':');
        $this->headers->add(\substr($line, 0, $c), \trim(\substr($line, $c + 1)));
        return $this;
    }

    /**
     * Closes the connection after this response: the server sends `Connection: close` and keeps no
     * keep-alive.
     *
     * @return Response  This response.
     */
    // Close the connection after this response.
    public function close(): Response
    {
        $this->close = true;
        return $this;
    }

    /**
     * Hands the connection to a closure once this response's head has been written. Used for protocol
     * upgrades such as WebSocket.
     *
     * The server writes the status line and headers, then calls `$fn($conn, $buf, $server)` with the
     * socket, a `Buffer\ByteBuffer` holding any bytes the client sent after the request head, and the
     * `Server`. The connection is closed when `$fn` returns and is never read as HTTP again. Only a
     * `101` response to an HTTP/1.1 request can take over, and only if the handler read any streamed
     * body fully; anything else is a handler bug and the server answers `500` without calling `$fn`.
     *
     * @param $fn  A `Closure(Resource, Buffer\ByteBuffer, Server): void` that owns the connection.
     * @return Response  This response.
     * @see Server
     */
    // Hand the connection to $fn once this response's head is written.
    //
    // Protocol-agnostic: the server writes the status line and headers, ends
    // the request context, and calls `$fn($conn, $buf, $server)` in the same
    // fiber — `$buf` holds whatever the peer sent past the request head. The
    // connection is closed when $fn returns and is never read as HTTP again.
    // Only a 101 on HTTP/1.1 may take over; anything else answers 500.
    public function takeover(\Closure $fn): Response
    {
        $this->takeoverFn = $fn;
        return $this;
    }

    /**
     * Whether `takeover()` was called.
     *
     * @return bool  `true` if a takeover closure is set.
     */
    public function isTakeover(): bool { return $this->takeoverFn !== null; }

    /** @internal */
    public function takeoverFn(): ?\Closure { return $this->takeoverFn; }

    /**
     * The string body set by `text()`, `html()`, `body()`, the constructor or `write()`. Empty for a
     * stream or a file.
     *
     * @return string  The body bytes.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Whether the body is produced by a closure set with `stream()`.
     *
     * @return bool  `true` for a streamed body.
     */
    public function isStreaming(): bool
    {
        return $this->bodyFn !== null;
    }

    /** @internal the Server's handle on the streaming closure */
    public function bodyFn(): mixed
    {
        return $this->bodyFn;
    }

    /**
     * Whether `close()` was called.
     *
     * @return bool  `true` if the handler asked to close the connection.
     */
    public function wantsClose(): bool
    {
        return $this->close;
    }

    /**
     * Whether the handler chose a status through the constructor or `status()`. If not, an ambient
     * `http_response_code()` decides.
     *
     * @return bool  `true` if a status was set explicitly.
     */
    public function statusWasSet(): bool
    {
        return $this->statusSet;
    }
}

/**
 * One connection's request parser: bytes in a {@see \Buffer\ByteBuffer} out the
 * front, a {@see Request} out the back.
 *
 * Incremental by construction — {@see parse} is called every time more bytes
 * land and answers {@see NEED} until a whole message is in hand — because a
 * server does not get to choose how a request is split across reads. The state
 * survives between calls; nothing is rescanned.
 *
 * The answer is a STATUS CODE, not a bool: {@see READY} when a request is
 * available, {@see NEED} when more bytes are wanted, and otherwise the exact
 * code the caller must send back. Every refusal in here is a wire-level one the
 * handler must never see.
 *
 * @internal
 */
final class Parser
{
    /** More bytes needed; nothing is wrong. */
    public const NEED = 0;
    /** The head is in and framed, and the client asked to be invited: write
     *  `HTTP/1.1 100 Continue\r\n\r\n` and call {@see parse} again. */
    public const CONTINUE_ = 100;
    /** A complete request is available from {@see request}. */
    public const READY = 200;

    private const ST_HEAD = 0;
    private const ST_BODY = 1;
    private const ST_CHUNK_SIZE = 2;
    private const ST_CHUNK_DATA = 3;
    private const ST_TRAILER = 4;
    private const ST_DONE = 5;

    /** Ceiling on a trailer section, which has no other bound. */
    private const TRAILER_MAX = 8192;

    private \Buffer\ByteBuffer $buf;
    private string $remoteAddr;
    private bool $secure;
    private int $maxHeaderBytes;
    private int $maxHeaderCount;
    private int $maxBodySize;

    private int $state = 0;
    /** How far the CRLFCRLF search has already looked, relative to the cursor.
     *  Without it a head arriving in 50 reads is scanned 50 times over. */
    private int $scan = 0;

    private string $method = '';
    private string $target = '';
    private string $path = '';
    private string $queryString = '';
    private string $version = '1.1';
    private ?Headers $headers = null;
    private string $body = '';
    private int $need = 0;
    private ?Request $req = null;
    private bool $streamed = false;
    private ?\Buffer\Reader $reader = null;

    /** The connection, when there is one. Only a STREAMED body needs it — the
     *  Reader handed to the handler reads on past what the buffer holds. */
    private ?\Resource $conn = null;
    private bool $streamBodies = false;
    /** @var array<int, array<int, string>> */
    private array $trusted = [];
    private int $proxyFlags = 0;
    private int $maxInputVars = 1000;
    private int $maxFileUploads = 20;
    private int $uploadMaxFilesize = 2097152;

    /** @param array<int, array<int, string>> $trusted */
    public function __construct(
        \Buffer\ByteBuffer $buf,
        string $remoteAddr = '',
        bool $secure = false,
        int $maxHeaderBytes = 16384,
        int $maxHeaderCount = 100,
        int $maxBodySize = 8388608,
        ?\Resource $conn = null,
        bool $streamBodies = false,
        array $trusted = [],
        int $proxyFlags = 0,
        int $maxInputVars = 1000,
        int $maxFileUploads = 20,
        int $uploadMaxFilesize = 2097152,
    ) {
        $this->buf = $buf;
        $this->remoteAddr = $remoteAddr;
        $this->secure = $secure;
        $this->maxHeaderBytes = $maxHeaderBytes;
        $this->maxHeaderCount = $maxHeaderCount;
        $this->maxBodySize = $maxBodySize;
        $this->conn = $conn;
        $this->streamBodies = $streamBodies;
        $this->trusted = $trusted;
        $this->proxyFlags = $proxyFlags;
        $this->maxInputVars = $maxInputVars;
        $this->maxFileUploads = $maxFileUploads;
        $this->uploadMaxFilesize = $uploadMaxFilesize;
    }

    /** The request, once {@see parse} has answered {@see READY}. */
    public function request(): ?Request
    {
        return $this->req;
    }

    /** Ready for the next message on this connection, buffer untouched. */
    public function reset(): void
    {
        $this->state = self::ST_HEAD;
        $this->scan = 0;
        $this->method = '';
        $this->target = '';
        $this->path = '';
        $this->queryString = '';
        $this->version = '1.1';
        $this->headers = null;
        $this->body = '';
        $this->need = 0;
        $this->req = null;
        $this->streamed = false;
        $this->reader = null;
    }

    /**
     * Consume whatever the buffer holds.
     *
     * @return int {@see READY}, {@see NEED}, or the status code to answer with
     */
    public function parse(): int
    {
        while (true) {
            if ($this->state === self::ST_DONE) {
                return self::READY;
            }
            if ($this->state === self::ST_HEAD) {
                $r = $this->head();
                if ($r !== self::READY) {
                    return $r;
                }
                continue;
            }
            if ($this->state === self::ST_BODY) {
                if ($this->buf->length() < $this->need) {
                    return self::NEED;
                }
                $this->body = $this->buf->read($this->need);
                $this->finish();
                continue;
            }
            if ($this->state === self::ST_CHUNK_SIZE) {
                $r = $this->chunkSizeLine();
                if ($r !== self::READY) {
                    return $r;
                }
                continue;
            }
            if ($this->state === self::ST_CHUNK_DATA) {
                // The trailing CRLF is part of the frame, so wait for it too —
                // otherwise the next size line reads as a chunk-ext of this one.
                if ($this->buf->length() < $this->need + 2) {
                    return self::NEED;
                }
                $this->body .= $this->buf->read($this->need);
                if ($this->buf->read(2) !== "\r\n") {
                    return 400;
                }
                $this->state = self::ST_CHUNK_SIZE;
                continue;
            }
            // ST_TRAILER — read and discard; a trailer nobody asked for (no `TE:
            // trailers`) is not something a handler may act on.
            $p = $this->buf->indexOf("\r\n");
            if ($p < 0) {
                if ($this->buf->length() > self::TRAILER_MAX) {
                    return 431;
                }
                return self::NEED;
            }
            if ($p === 0) {
                $this->buf->skip(2);
                $this->finish();
                continue;
            }
            $this->buf->skip($p + 2);
        }
    }

    /** The head, from the CRLFCRLF search through the framing decision. */
    private function head(): int
    {
        $end = $this->buf->indexOf("\r\n\r\n", $this->scan);
        if ($end < 0) {
            $len = $this->buf->length();
            // Resume 3 bytes back: the terminator may straddle this read.
            $this->scan = $len > 3 ? $len - 3 : 0;
            if ($len > $this->maxHeaderBytes) {
                return 431;
            }
            return self::NEED;
        }
        if ($end + 4 > $this->maxHeaderBytes) {
            return 431;
        }
        $head = $this->buf->peek($end);
        $this->buf->skip($end + 4);
        $this->scan = 0;

        $rlEnd = \strpos($head, "\r\n");
        if ($rlEnd === false) {
            $rlEnd = \strlen($head);
        }
        $rl = reqLine(\substr($head, 0, $rlEnd));
        if (\count($rl) !== 3) {
            return 400;
        }
        $this->method = $rl[0];
        $target = $rl[1];
        $this->version = $rl[2];
        if ($this->version !== '1.1' && $this->version !== '1.0') {
            return 505;
        }

        $h = new Headers();
        $r = $this->headerLines($head, $rlEnd + 2, $h);
        if ($r !== self::READY) {
            return $r;
        }
        $this->headers = $h;

        // A repeated Host arrives here comma-joined, and two intermediaries
        // reading a different one of them is exactly the routing-confusion bug.
        $host = $h->get('host');
        if (\strpos($host, ',') !== false) {
            return 400;
        }
        if ($this->version === '1.1' && !$h->has('host')) {
            return 400;
        }

        $r = $this->targetForms($target, $h);
        if ($r !== self::READY) {
            return $r;
        }
        $r = $this->framing($h);
        if ($r !== self::READY || $this->state === self::ST_DONE) {
            // Either a refusal, or a message with no body at all — in both
            // cases there is nothing to invite. NEVER answer 100 for a request
            // whose framing already produced a 413/411/400: inviting a body you
            // have decided to refuse is how a client is made to send megabytes
            // into a closed socket.
            return $r;
        }
        $expect = \trim(\strtolower($h->get('expect')));
        if ($expect === '') {
            return self::READY;
        }
        if ($expect !== '100-continue') {
            return 417;
        }
        if ($this->version !== '1.1') {
            // 1.0 has no Expect. Ignore it and read the body.
            return self::READY;
        }
        // The caller writes the interim response and calls parse() again; the
        // state is already the body's, so nothing is re-parsed.
        return self::CONTINUE_;
    }

    /**
     * Walk the header lines of `$head` from `$pos` into `$h`.
     *
     * Directly over the block, rather than `splitHead()` + `headerSplit()` per
     * line, and the reason is allocation VOLUME — which is what a request head
     * actually costs. Measured: parse time is linear in the header count at
     * 379 ns each, and the old path allocated about six times per header (the
     * exploded line, the two-element split array, the name, the value, its
     * trim, the lowercased key). Three of those existed only to hand the
     * pieces between two functions. Nothing here is cheaper per BYTE — the
     * same scanning happens — there is simply less of it kept.
     *
     * obs-fold (§3.2.4) is why a line is not emitted the moment it is read: a
     * continuation belongs to the PREVIOUS field, so one field is always held
     * back and flushed when the next real one starts.
     */
    private function headerLines(string $head, int $pos, Headers $h): int
    {
        $len = \strlen($head);
        $count = 0;
        $name = '';
        $value = '';
        $have = false;
        while ($pos < $len) {
            $eol = \strpos($head, "\r\n", $pos);
            if ($eol === false) {
                $eol = $len;
            }
            if ($eol === $pos) {
                $pos = $eol + 2;
                continue;
            }
            $c0 = $head[$pos];
            if ($c0 === ' ' || $c0 === "\t") {
                if (!$have) {
                    // A fold with nothing to fold into.
                    return 400;
                }
                $value = $value . ' ' . \trim(\substr($head, $pos, $eol - $pos));
                $pos = $eol + 2;
                continue;
            }
            if ($have) {
                $h->add($name, $value);
                $count++;
                if ($count > $this->maxHeaderCount) {
                    return 431;
                }
            }
            $colon = \strpos($head, ':', $pos);
            if ($colon === false || $colon >= $eol || $colon === $pos) {
                return 400;
            }
            $name = \substr($head, $pos, $colon - $pos);
            // Rejects whitespace before the colon, which §3.2.4 forbids
            // precisely because two intermediaries disagree about whether
            // `Content-Length : 5` is a Content-Length.
            if (!tokenOk($name)) {
                return 400;
            }
            $value = \trim(\substr($head, $colon + 1, $eol - $colon - 1), " \t");
            $have = true;
            $pos = $eol + 2;
        }
        if ($have) {
            $h->add($name, $value);
            $count++;
            if ($count > $this->maxHeaderCount) {
                return 431;
            }
        }
        return self::READY;
    }

    /**
     * origin-form, absolute-form and the asterisk-form of a request-target.
     *
     * absolute-form is not optional to support (§5.3.2): it is what a request
     * through a proxy looks like, and §5.4 says its authority WINS over any
     * Host header — so the Host is rewritten rather than compared.
     */
    private function targetForms(string $target, Headers $h): int
    {
        $this->target = $target;
        if ($target === '*') {
            if ($this->method !== 'OPTIONS') {
                return 400;
            }
            $this->path = '*';
            $this->queryString = '';
            return self::READY;
        }
        if ($target[0] !== '/') {
            $sep = \strpos($target, '://');
            if ($sep === false) {
                // authority-form: only CONNECT, which is a tunnel, not a request
                // this server answers.
                return $this->method === 'CONNECT' ? 501 : 400;
            }
            $rest = \substr($target, $sep + 3);
            $slash = \strpos($rest, '/');
            if ($slash === false) {
                $h->set('Host', $rest);
                $target = '/';
            } else {
                $h->set('Host', \substr($rest, 0, $slash));
                $target = \substr($rest, $slash);
            }
        }
        $sp = splitPath($target);
        $path = normPath($sp[0]);
        if ($path === '') {
            // A NUL in the path: whatever consumes it downstream truncates.
            return 400;
        }
        $this->path = $path;
        $this->queryString = $sp[1];
        return self::READY;
    }

    /**
     * Which of the three framings this message uses (§3.3.3).
     *
     * `Transfer-Encoding` and `Content-Length` together is refused outright.
     * The RFC lets a server drop the Content-Length and go chunked, but the two
     * numbers can only be compared by decoding the body — and every recipient
     * that resolves the ambiguity differently is one half of a smuggled
     * request. Refusing costs a 400 on a message no correct client sends.
     */
    private function framing(Headers $h): int
    {
        $te = $h->get('transfer-encoding');
        $cl = $h->get('content-length');
        if ($te !== '') {
            if ($cl !== '') {
                return 400;
            }
            if (!teIsChunked($te)) {
                // No determinable length: §3.3.3 rule 3.
                return 400;
            }
            $this->state = self::ST_CHUNK_SIZE;
            return self::READY;
        }
        if ($cl !== '') {
            $len = contentLength($cl);
            if ($len < 0) {
                return 400;
            }
            if ($len > $this->maxBodySize) {
                // Too big to buffer. With streaming on it is handed over as a
                // Reader with the length as its budget, so the handler decides
                // what to do with it; otherwise the answer is 413.
                if (!$this->streamBodies || $this->conn === null) {
                    return 413;
                }
                $this->reader = new \Buffer\Reader($this->conn, $this->buf, $len);
                $this->streamed = true;
                $this->finish();
                return self::READY;
            }
            if ($len === 0) {
                $this->finish();
                return self::READY;
            }
            $this->need = $len;
            $this->state = self::ST_BODY;
            return self::READY;
        }
        $m = Method::tryFrom($this->method);
        if ($m !== null && $m->allowsBody()) {
            // A body-bearing method with no framing at all: the server cannot
            // know where it ends, and guessing is the smuggling bug again.
            return 411;
        }
        $this->finish();
        return self::READY;
    }

    /** One chunk size line, chunk-ext discarded. */
    private function chunkSizeLine(): int
    {
        $hdr = chunkHdr($this->buf->buf, $this->buf->pos);
        if ($hdr === -2) {
            return 400;
        }
        if ($hdr === -1) {
            return self::NEED;
        }
        $size = chunkSize($this->buf->buf, $this->buf->pos);
        if ($size < 0) {
            return 400;
        }
        $this->buf->skip($hdr);
        if ($size === 0) {
            $this->state = self::ST_TRAILER;
            return self::READY;
        }
        // A chunked body declares no total, so the cap is checked per chunk —
        // the only point at which it CAN be checked.
        if (\strlen($this->body) + $size > $this->maxBodySize) {
            return 413;
        }
        $this->need = $size;
        $this->state = self::ST_CHUNK_DATA;
        return self::READY;
    }

    /** Materialise the Request and stop. */
    private function finish(): void
    {
        $h = $this->headers ?? new Headers();
        $remote = $this->remoteAddr;
        $secure = $this->secure;
        $fport = '';
        if (\count($this->trusted) > 0) {
            $r = resolveForwarded($h, $this->remoteAddr, $this->secure, $this->trusted, $this->proxyFlags);
            $remote = $r[0];
            $secure = $r[1] === '1';
            $fport = $r[2];
        }
        $this->req = new Request(
            $this->method,
            $this->target,
            $this->path,
            $this->queryString,
            $this->version,
            $h,
            $this->body,
            $this->streamed,
            $remote,
            $secure,
            $this->remoteAddr,
            $fport,
            $this->maxInputVars,
            $this->maxFileUploads,
            $this->uploadMaxFilesize,
            $this->reader,
        );
        $this->state = self::ST_DONE;
    }
}

/**
 * One connection's outbound queue: parts in, one `writev(2)` out.
 *
 * A keep-alive client that PIPELINES sends several requests in one packet, and
 * the parser answers all of them from one buffer — but a `fwrite` per response
 * turns that back into one syscall each. Queuing the parts and handing the
 * vector to the kernel once collapses a batch of N responses into ONE write,
 * which is the same trick the head+body vector already plays within a single
 * response.
 *
 * The parts are NOT concatenated: `fwrite`'s array form is a real `writev`, so
 * a 1 MiB body is never copied into a staging buffer. The two ceilings are
 * what keep that honest — a queue is flushed once it is either long enough to
 * approach `IOV_MAX` or big enough that holding it buys nothing.
 *
 * @internal
 */
final class Outbox
{
    /** Well under IOV_MAX (1024 on both hosts), so a vector never has to be
     *  split by the kernel. */
    private const MAX_PARTS = 64;

    /** Past this the queue is already worth a syscall on its own. */
    private const MAX_BYTES = 262144;

    private static array<int, string> $empty = [];

    private \Resource $conn;
    private array<int, string> $parts = [];
    private int $n = 0;
    private int $bytes = 0;

    public function __construct(\Resource $conn)
    {
        $this->conn = $conn;
    }

    public function add(string $s): void
    {
        if ($s === '') {
            return;
        }
        $this->parts[$this->n] = $s;
        $this->n = $this->n + 1;
        $this->bytes = $this->bytes + \strlen($s);
        if ($this->n >= self::MAX_PARTS || $this->bytes >= self::MAX_BYTES) {
            $this->flush();
        }
    }

    /** Queue and send in one go — for anything a peer is WAITING on. */
    public function sendNow(string $s): void
    {
        $this->add($s);
        $this->flush();
    }

    public function flush(): void
    {
        if ($this->n === 0) {
            return;
        }
        // Take the queue OUT of the slot before the write: the snapshot is a
        // reference of this frame's own, so the write may park on back-pressure
        // and another task may queue and flush meanwhile without either
        // freeing a part under the parked writer — and the slot is free to
        // release its previous buffer on every reset. Always the vectored
        // form, one part or many.
        $parts = $this->parts;
        $this->parts = self::$empty;
        $this->n = 0;
        $this->bytes = 0;
        \fwrite($this->conn, $parts);
    }

    public function pending(): int
    {
        return $this->bytes;
    }
}

/**
 * The request being served on the current flow, or `null` outside one.
 *
 * For code that is too deep to be handed the `Request`: a logger, a repository, an error page. The
 * server binds the request in a per-request `Async\Context` scope, so it is visible in the handler
 * and in every task the handler spawns, and in nothing else. That keeps it correct with many
 * requests in flight. Bind your own request-scoped values with `Async\Context::withValue()`.
 *
 * @return ?Request  The current request, or `null` when called outside a request.
 */
// The Request being served on this flow, or null outside one.
//
// The ambient reader for code too deep to be handed the Request — a logger, a
// repository, an error page. It reads the per-request `Async\Context` scope
// {@see Server} opens, so it is visible inside any task the handler spawns and
// in nothing outside that request, which is what makes it safe with many
// requests in flight at once. Bind your own the same way:
// `Async\Context::withValue('app.user', $u, fn() => …)`.
function request(): ?Request
{
    $v = \Async\Context::value(Server::CTX_REQUEST);
    if ($v instanceof Request) {
        return $v;
    }
    return null;
}

/**
 * The writer a streaming response body receives; see `Response::stream()`.
 *
 * For an HTTP/1.1 client each `write()` becomes one chunk of `Transfer-Encoding: chunked`. For an
 * HTTP/1.0 client, which has no chunked encoding, bytes go out raw and the close of the connection
 * ends the body. The server writes the terminating chunk itself when the closure returns.
 *
 * @example doc_http_stream
 * @see Response
 */
// The writer a streaming response body is handed.
//
// Chunked framing lives HERE rather than in `Buffer\Writer`, and deliberately:
// `Buffer\` is parsed before `Http\`, so a buffer that knew about
// `Transfer-Encoding` would invert the dependency. This composes the plain
// writer instead.
//
// `$framed` is false for an HTTP/1.0 peer, which has no chunked encoding: the
// bytes go out raw and the connection close IS the framing, which is why the
// Server forces `Connection: close` on that path.
final class ChunkedWriter
{
    private \Buffer\Writer $w;
    private bool $framed;
    private bool $ended = false;

    /**
     * Created by the server for each streamed response.
     *
     * @param $w  The underlying writer.
     * @param $framed  Whether to add chunked framing (false for HTTP/1.0 peers).
     * @internal
     */
    public function __construct(\Buffer\Writer $w, bool $framed = true)
    {
        $this->w = $w;
        $this->framed = $framed;
    }

    /**
     * Queues one chunk. An empty string is ignored, because a zero-length chunk would end the body.
     *
     * @param $s  Bytes to send.
     */
    //  Write one chunk. An empty write is dropped — a zero-length chunk is the
    //  TERMINATOR, so sending one here would end the body early.
    public function write(string $s): void
    {
        if ($s === '') {
            return;
        }
        $this->w->write($this->framed ? chunkFrame($s) : $s);
    }

    /**
     * Sends everything queued to the client now. Without it the writer batches output (up to 64 KiB)
     * before writing to the socket.
     */
    // Push what is queued to the socket — what makes streaming observable.
    public function flush(): void
    {
        $this->w->flush();
    }

    /**
     * Writes the terminating zero-length chunk and flushes. Idempotent. The server calls it for you
     * after the closure returns, so a handler normally never needs to.
     */
    // The terminating zero-length chunk. Idempotent; the Server calls it.
    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        if ($this->framed) {
            $this->w->write("0\r\n\r\n");
        }
        $this->w->flush();
    }

    /**
     * The number of bytes handed to the socket so far, chunk framing included. Bytes still queued are
     * not counted.
     *
     * @return int  The byte count.
     */
    public function bytesWritten(): int
    {
        return $this->w->bytesWritten();
    }

    /**
     * Whether chunked framing is in use (`false` for an HTTP/1.0 client).
     *
     * @return bool  `true` for chunked framing.
     */
    public function isChunked(): bool
    {
        return $this->framed;
    }
}

/** @internal One connection's pending takeover, set by Server::serveOne. */
final class Takeover
{
    public ?Response $res = null;
}

/**
 * An HTTP/1.1 server: `(new Server($addr))->serve($handler)`, where the handler is
 * `callable(Request): Response`.
 *
 * ```php
 * use Http\{Request, Response, Server};
 *
 * (new Server('tcp://127.0.0.1:8080'))->serve(function (Request $req): Response {
 *     return (new Response())->text('hello ' . $req->query('name', 'world') . "\n");
 * });
 * ```
 *
 * The binary is the server: there is no fpm and no worker pool to configure. One process serves
 * many connections at once. Each connection is a task, and reads and writes suspend the task
 * instead of blocking the process, so a handler may use ordinary blocking-looking code. A `tls://`
 * address serves TLS.
 *
 * Configure with the fluent setters below before calling `serve()`. Limits and timeouts and their
 * defaults:
 *
 * | setter | default | on breach |
 * |---|---|---|
 * | `maxHeaderBytes` | 16384 | `431` |
 * | `maxHeaderCount` | 100 | `431` |
 * | `maxBodySize` | 8388608 | `413`, or the body is streamed |
 * | `maxFileUploads` | 20 | further file parts are dropped |
 * | `uploadMaxFilesize` | 2097152 | the part is kept with `ERR_INI_SIZE` |
 * | `maxInputVars` | 1000 | extra pairs are dropped silently |
 * | `maxConnections` | 4096 | the worker stops accepting; the kernel backlog queues |
 * | `keepAliveMax` | 0 | the connection closes after N requests; 0 means never |
 * | `idleTimeout` | 5.0 s | silent close between requests |
 * | `headerTimeout` | 10.0 s | `408`, once a request has started arriving |
 * | `writeTimeout` | 30.0 s | the write is abandoned |
 *
 * Requests the server refuses before the handler runs get a bodyless response and a closed
 * connection: `400` for a malformed request line or header, a header name followed by whitespace
 * before the colon, HTTP/1.1 without `Host`, or `Transfer-Encoding` together with
 * `Content-Length`; `411` for a body-bearing method without framing; `413`, `417` for an `Expect`
 * other than `100-continue`, `431`, `501` for `CONNECT`, and `505` for any version other than 1.0
 * and 1.1. `Expect: 100-continue` is answered only after the request head has been accepted.
 *
 * If the handler throws, the server answers `500` (or calls `onError()`) and keeps serving.
 * Forwarded headers are ignored unless the peer is listed in `trustedProxies()`.
 *
 * @example doc_http_server_basic
 * @example doc_http_limits
 * @see Request
 * @see Response
 * @see Proxy
 */
// An HTTP/1.1 server: `(new Server($addr))->serve($handler)`, where `$handler`
// is `callable(Request): Response`.
//
// Written on ORDINARY blocking-style stream I/O — `stream_socket_accept`,
// `fread`, `fwrite`, `fclose` — never `Async\read`/`Async\write`. Inside
// `Async\async()` every one of those routes its would-block through the
// netpoller and suspends the fiber, so this reads like a blocking server and
// runs like an evented one. It also means a `tls://` listener works: the raw
// async path carries neither buffering nor TLS.
//
// Concurrency is a per-connection task under one {@see \Async\TaskGroup}, so
// the scope cannot close while a request is in flight and cancelling it
// cancels every connection. The permit is taken BEFORE the accept: at the
// ceiling this worker simply stops accepting and the queue stays in the
// kernel's backlog, which is what backpressure means for a server.
final class Server
{
    /** Bytes asked of the socket per read. */
    private const READ_CHUNK = 8192;

    /**
     * The `Async\Context` key under which the current `Request` is bound for the duration of a request
     * (`'http.request'`). Read it with `Http\request()`.
     */
    // The Async\Context key the current Request is bound under. {@see request}
    public const CTX_REQUEST = 'http.request';

    private string $addr;
    private mixed $context;
    private ?\Resource $listener = null;
    private bool $ownsListener = true;

    private mixed $handler = null;
    private mixed $onError = null;

    private int $workerCount = 0;
    private int $maxConnections = 4096;
    private bool $compat = false;
    private bool $captureEcho = true;
    private float $idleTimeout = 5.0;
    private float $headerTimeout = 10.0;
    private float $writeTimeout = 30.0;
    private int $maxHeaderBytes = 16384;
    private int $maxHeaderCount = 100;
    private int $maxBodySize = 8388608;
    private bool $streamBodies = false;
    /** gzip, off by default — {@see compression}. The FLAG is what keeps the
     *  deflater out of a program that never asks for it. */
    private bool $compress = false;
    private int $compressMin = 1024;
    private int $compressLevel = 6;
    /** Requests per connection before it is closed. 100 tore a connection
     *  down and rebuilt it — accept, close, and a TLS handshake if any — every
     *  hundred requests; nginx's equivalent default is 1000. It is a DoS knob,
     *  not a correctness one. */
    private int $keepAliveMax = 0;
    private int $backlog = 511;
    private string $serverName = 'manticore';
    private bool $secure = false;
    /** @var array<int, array<int, string>> parsed CIDRs, {@see trustedProxies} */
    private array $trustedProxies = [];
    private int $proxyFlags = 0;
    /** php's `max_input_vars`, {@see maxInputVars}. */
    private int $maxInputVars = 1000;
    private int $maxFileUploads = 20;
    private int $uploadMaxFilesize = 2097152;
    /** php's `post_max_size`. Reserved — not enforced in either mode. */
    private int $postMaxSize = 0;

    /** How long one `accept` waits before the loop re-reads {@see $stopped}.
     *  This — not closing the listener out from under a parked accept — is what
     *  bounds shutdown latency, and it is the only part of the loop a caller on
     *  another task can affect without racing the reactor. */
    private float $acceptWait = 0.25;

    /** The `Date:` value, and the second it was rendered for. An IMF-fixdate
     *  changes once a second and gmdate() is not cheap: rendering it per
     *  request cost 14% of throughput on a static plaintext route (54.8k →
     *  62.7k rps, measured). A worker is one process and one thread, so a
     *  static cache is exactly right. */
    private static int $dateSec = 0;
    private static string $dateStr = '';

    private bool $stopped = false;
    private int $statServed = 0;
    private int $statOpen = 0;
    private int $statAccepted = 0;
    private int $statErrors = 0;
    private int $statUpgraded = 0;
    /** @var array<int, \Closure> */
    private array<int, \Closure> $stopHooks = [];
    private int $stopSeq = 0;

    /**
     * Creates a server. Nothing is bound until `serve()` is called.
     *
     * An address starting with `tls://` or `ssl://` makes the connections TLS; give the certificate in
     * the stream context.
     *
     * @param $addr  A stream-socket address such as `tcp://0.0.0.0:8080`.
     * @param $context  An optional stream context, for example with `ssl` options or `socket.backlog`.
     */
    public function __construct(string $addr = 'tcp://127.0.0.1:8080', mixed $context = null)
    {
        $this->addr = $addr;
        $this->context = $context;
        $this->secure = \strncmp($addr, 'tls://', 6) === 0 || \strncmp($addr, 'ssl://', 6) === 0;
    }

    /**
     * Creates a server over a listener you have already bound.
     *
     * This is what socket activation needs, and it lets a program bind its own port (or port 0), hand
     * the socket over and run a client in the same process. The server does not close a listener it
     * did not open.
     *
     * @param $listener  A bound, listening stream socket server, in non-blocking mode.
     * @param $secure  Whether connections accepted from it are TLS.
     * @return Server  A new server.
     * @example doc_http_server_basic
     */
    // Serve an ALREADY-BOUND listener.
    //
    // What socket activation needs, and what makes this testable: a case binds
    // its own port (scanning for a free one), hands the resource over and keeps
    // the client side in the same process. The Server does not close a listener
    // it did not open.
    public static function onListener(\Resource $listener, bool $secure = false): Server
    {
        $s = new Server('', null);
        $s->listener = $listener;
        $s->ownsListener = false;
        $s->secure = $secure;
        return $s;
    }

    /**
     * Serves with N forked worker processes sharing one listener.
     *
     * The listener is bound once, then N workers inherit it; the kernel balances accepts between them.
     * A supervisor restarts a worker that crashes and forwards `SIGTERM` and `SIGINT` to all of them.
     * The parent serves nothing, and `serve()` returns once every worker has exited. `0` (the default)
     * serves in the current process. Ignored when `serve()` is called from inside an `Async` scope.
     *
     * @param $n  Number of workers.
     * @return Server  This server.
     */
    public function workers(int $n): Server { $this->workerCount = $n; return $this; }
    /**
     * The ceiling on simultaneous connections for this worker; default 4096 (a live connection costs
     * about 32 KB).
     *
     * A permit is taken before `accept`, so at the ceiling the worker stops accepting and new
     * connections wait in the kernel backlog. Values below 1 are raised to 1.
     *
     * @param $n  Connections.
     * @return Server  This server.
     */
    public function maxConnections(int $n): Server { $this->maxConnections = $n < 1 ? 1 : $n; return $this; }
    /**
     * Seeds php's superglobals for every request, so existing php code can run in a handler. Off by
     * default.
     *
     * When on, `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_REQUEST`, `$_FILES` and `$_SESSION` are
     * filled per request (and `session_start()` works), and from `serve()` on `PHP_SAPI` and
     * `php_sapi_name()` answer `cli-server`, process-wide. The superglobals are request-bound:
     * concurrent requests do not see each other's. A streamed body is not seeded into `$_POST` or
     * `$_FILES`. `header()`, `setcookie()`, `http_response_code()` and `echo` work in every handler
     * regardless of this setting.
     *
     * @param $on  Whether to seed the superglobals.
     * @return Server  This server.
     * @example doc_http_ambient
     */
    public function compat(bool $on): Server { $this->compat = $on; return $this; }
    /**
     * Whether bytes a handler `echo`es become the response body. On by default.
     *
     * The echoed text is used only when the returned `Response` has no body and is not a stream or a
     * file. If both exist, the explicit body wins and the echoed bytes are dropped.
     *
     * @param $on  Whether to capture output.
     * @return Server  This server.
     */
    public function captureEcho(bool $on): Server { $this->captureEcho = $on; return $this; }
    /**
     * How long a connection may sit silent between requests before it is closed, in seconds. Default
     * `5.0`. A client that sent nothing is closed without a response.
     *
     * @param $s  Seconds; fractions are allowed.
     * @return Server  This server.
     */
    public function idleTimeout(float $s): Server { $this->idleTimeout = $s; return $this; }
    /**
     * How long the rest of a request may take to arrive once its first bytes are in, in seconds.
     * Default `10.0`. A client that stalls past it gets `408` and the connection is closed, so a
     * client dribbling a head cannot hold a slot open.
     *
     * @param $s  Seconds; fractions are allowed.
     * @return Server  This server.
     */
    public function headerTimeout(float $s): Server { $this->headerTimeout = $s; return $this; }
    /**
     * The timeout on the socket while a response is being written, in seconds. Default `30.0`. It
     * bounds a client that stops reading.
     *
     * @param $s  Seconds; fractions are allowed.
     * @return Server  This server.
     */
    public function writeTimeout(float $s): Server { $this->writeTimeout = $s; return $this; }
    /**
     * The largest request head (request line plus headers) accepted, in bytes. Default `16384`. A
     * larger head is answered with `431` and the connection is closed.
     *
     * @param $n  Bytes.
     * @return Server  This server.
     */
    public function maxHeaderBytes(int $n): Server { $this->maxHeaderBytes = $n; return $this; }
    /**
     * The largest number of header fields accepted in a request. Default `100`. More is answered with
     * `431` and the connection is closed.
     *
     * @param $n  Fields.
     * @return Server  This server.
     */
    public function maxHeaderCount(int $n): Server { $this->maxHeaderCount = $n; return $this; }
    /**
     * The largest request body that is buffered, in bytes. Default `8388608` (8 MiB).
     *
     * A larger declared `Content-Length` is answered with `413`, unless `streamBodies(true)` is on, in
     * which case the handler gets `Request::stream()`. A chunked body is always buffered, so the cap
     * is checked against its running total and a larger one is a `413`.
     *
     * @param $n  Bytes.
     * @return Server  This server.
     */
    public function maxBodySize(int $n): Server { $this->maxBodySize = $n; return $this; }
    /**
     * Lets a body larger than `maxBodySize` be read incrementally instead of being refused with `413`.
     * Off by default.
     *
     * The handler then reads `Request::stream()` (or `Request::multipart()` for an upload), with the
     * declared length as its budget. Smaller bodies are still buffered. A body the handler ignores is
     * drained before the connection is reused.
     *
     * @param $on  Whether to stream oversized bodies.
     * @return Server  This server.
     */
    public function streamBodies(bool $on): Server { $this->streamBodies = $on; return $this; }

    /**
     * Enables gzip for buffered text-like responses. Off by default.
     *
     * A response is compressed when its `Content-Type` is textual (`text/*`, JSON, JavaScript, XML,
     * SVG, WebAssembly or a `+json`/`+xml` type), the body is at least `$minBytes` long, the client's
     * `Accept-Encoding` allows gzip, and no `Content-Encoding` is set. A strong `ETag` becomes weak.
     * `Vary: Accept-Encoding` is added to every response of a compressible type, compressed or not.
     * Streamed bodies are never compressed, and a file is never compressed on the fly: a `<path>.gz`
     * sibling that is not older than the file is sent instead.
     *
     * @param $on  Whether to compress.
     * @param $minBytes  Smallest body to compress, in bytes; negative values count as 0.
     * @param $level  gzip level, passed to `gzencode()`.
     * @return Server  This server.
     * @example doc_http_compression
     */
    // gzip buffered bodies of text-like types of at least `$minBytes`, to a
    // client whose `Accept-Encoding` grants it. Off by default: the deflater is
    // pure PHP and a program that never calls this carries none of it.
    //
    // A FILE is never deflated on the fly — a `<path>.gz` sibling is served
    // instead ({@see Response::useGzSibling}). Streamed bodies are not
    // compressed at all: `gzencode` is one-shot.
    public function compression(bool $on, int $minBytes = 1024, int $level = 6): Server
    {
        $this->compress = $on;
        $this->compressMin = $minBytes < 0 ? 0 : $minBytes;
        $this->compressLevel = $level;
        return $this;
    }
    /**
     * Closes a connection after this many requests. `0` (the default) never closes it for that reason.
     *
     * @param $n  Requests per connection.
     * @return Server  This server.
     */
    // Close a connection after N requests; 0 (the default) never does.
    public function keepAliveMax(int $n): Server { $this->keepAliveMax = $n; return $this; }
    /**
     * The length of the listen queue; default `511`. The kernel clamps it to `somaxconn`, and a full
     * queue makes a connect fail with a reset. A `socket.backlog` option in the stream context takes
     * precedence.
     *
     * @param $n  Queue length.
     * @return Server  This server.
     */
    // The listen() queue; the kernel clamps it to somaxconn. A context's own `socket.backlog` wins.
    public function backlog(int $n): Server { $this->backlog = $n; return $this; }
    /**
     * The value of the `Server:` response header; default `manticore`. An empty string omits the
     * header. A handler's own `Server` header is kept.
     *
     * @param $s  Header value.
     * @return Server  This server.
     */
    // '' omits the `Server:` header entirely.
    public function serverName(string $s): Server { $this->serverName = $s; return $this; }
    /**
     * How long, in seconds, an idle accept waits before the loop checks whether `stop()` was called.
     * Default `0.25`. It bounds shutdown latency; lower it in tests.
     *
     * @param $s  Seconds.
     * @return Server  This server.
     */
    public function acceptWait(float $s): Server { $this->acceptWait = $s; return $this; }
    /**
     * php's `max_input_vars` for `queryArray()` and `postArray()` (and the superglobals): default
     * `1000`. Pairs beyond the limit are dropped silently. Values below 1 are raised to 1.
     *
     * @param $n  Variables.
     * @return Server  This server.
     */
    // php's `max_input_vars` for $_GET / $_POST (1000, like php.ini's default).
    public function maxInputVars(int $n): Server { $this->maxInputVars = $n < 1 ? 1 : $n; return $this; }
    /**
     * php's `max_file_uploads`: the most file parts kept per multipart request; default `20`. Further
     * file parts are dropped. Negative values count as 0.
     *
     * @param $n  Files.
     * @return Server  This server.
     */
    // php's `max_file_uploads` (20).
    public function maxFileUploads(int $n): Server { $this->maxFileUploads = $n < 0 ? 0 : $n; return $this; }
    /**
     * php's per-file `upload_max_filesize`, in bytes; default `2097152` (2 MiB). A larger file part is
     * kept with `UploadedFile::ERR_INI_SIZE`, no temporary file and size 0. Negative values count as
     * 0.
     *
     * @param $n  Bytes.
     * @return Server  This server.
     */
    // php's per-file `upload_max_filesize` (2 MiB).
    public function uploadMaxFilesize(int $n): Server { $this->uploadMaxFilesize = $n < 0 ? 0 : $n; return $this; }
    /**
     * php's `post_max_size`. Reserved — not enforced in either mode: a
     * buffered body is already bounded by {@see maxBodySize}, and a streamed
     * one's field bytes are the handler's ({@see Part::readAll}).
     *
     * @param $n  Bytes.
     * @return Server  This server.
     */
    public function postMaxSize(int $n): Server { $this->postMaxSize = $n < 0 ? 0 : $n; return $this; }
    /**
     * Sets the function that turns an exception thrown by a handler into a response.
     *
     * Without it the server answers `500 Internal Server Error` and closes the connection. If this
     * function throws too, the same canned `500` is used. Cancellation (shutdown) is not passed to it.
     *
     * @param $fn  A `callable(Throwable, Request): Response`.
     * @return Server  This server.
     * @example doc_http_limits
     */
    // `callable(\Throwable, ?Request): Response`
    public function onError(callable $fn): Server { $this->onError = $fn; return $this; }

    /**
     * Declares which peers are reverse proxies whose forwarded headers are believed. Off unless
     * called, because any client can send those headers.
     *
     * When the connecting peer is in `$cidrs`, `X-Forwarded-For` is walked from the right to the first
     * hop that is not itself trusted, and that hop becomes `Request::$remoteAddr`. A hop that is not
     * an IP address (`unknown`, an obfuscated identifier) is skipped. `X-Forwarded-Proto`, `-Host` and
     * `-Port` take their first (leftmost) value and set `Request::$secure`, the `Host` header and
     * `Request::$forwardedPort`. Only the headers whose flag is in `$flags` are used.
     * `Request::$peerAddr` is always the socket's own address. With `Proxy::FORWARDED`, RFC 7239
     * `Forwarded:` is read too and wins for the fields it names.
     *
     * A dual-stack listener shows an IPv4 client as `::ffff:a.b.c.d`; a plain IPv4 CIDR never matches
     * it, so list the mapped form (`::ffff:10.0.0.0/104`) or an IPv6 prefix.
     *
     * @param array<int, string> $cidrs  Addresses or networks such as `10.0.0.0/8`, `127.0.0.1` or `2001:db8::/32`.
     * @param $flags  Which headers to trust: `Proxy` bits, `Proxy::ALL` by default.
     * @return Server  This server.
     * @throws \InvalidArgumentException  when an entry is not a valid address or CIDR
     * @example doc_http_proxy
     * @see Proxy
     */
    // Peers whose `X-Forwarded-*` / `Forwarded` headers are believed. Off
    // unless called. A malformed entry throws here, at configuration time.
    public function trustedProxies(array<int, string> $cidrs, int $flags = Proxy::ALL): Server
    {
        $parsed = [];
        foreach ($cidrs as $c) {
            $net = cidrParse($c);
            if ($net === null) {
                throw new \InvalidArgumentException('Http\\Server: bad trusted proxy ' . $c);
            }
            $parsed[] = $net;
        }
        $this->trustedProxies = $parsed;
        $this->proxyFlags = $flags;
        return $this;
    }

    /**
     * Runs the server until `stop()` is called or the process is told to shut down.
     *
     * Called from outside an `Async` scope (the normal case) it binds the address, installs `SIGTERM`
     * and `SIGINT` handlers, and blocks until shutdown; with `workers(N)` it forks the workers
     * instead. Called from inside an `Async` scope it runs the accept loop as a task of that scope,
     * which lets one program host both the server and a client, as the examples do. Requests in flight
     * at shutdown are finished, not cut.
     *
     * Each request runs the handler inside its own `Async\Context` scope, so `Http\request()` and
     * values bound with `Async\Context::withValue()` are visible to tasks the handler spawns and to
     * nothing else.
     *
     * @param $handler  A `callable(Request): Response`.
     * @throws \RuntimeException  when the address cannot be bound
     * @example doc_http_server_basic
     * @see Request
     * @see Response
     */
    // Run until {@see stop} (or cancellation). `$handler` is
    // `callable(Request): Response`.
    //
    // Reentrant on purpose: called from INSIDE an `Async\async()` scope it just
    // runs the loop as a task of that scope — which is what lets one process
    // host both the server and its client, i.e. what makes the suite offline.
    // Called from outside it opens its own scope and installs the shutdown
    // signals, which is what an application wants.
    public function serve(callable $handler): void
    {
        $this->handler = $handler;
        if ($this->compat) {
            \__mc_sapi_server(1);
        }
        if (\Async\Context::currentScope() !== null) {
            $this->loop();
            return;
        }
        // Bind BEFORE any fork and before any reactor: the children inherit
        // one listener fd and the kernel balances accepts between them.
        $this->bind();
        if ($this->workerCount > 0) {
            \Process\supervise($this->workerCount, function (int $i): void {
                \Async\async(function () {
                    \Async\shutdownOn(\SIGTERM, \SIGINT);
                    $this->loop();
                });
            });
            if ($this->ownsListener && $this->listener !== null) {
                \fclose($this->listener);
                $this->listener = null;
            }
            return;
        }
        \Async\async(function () {
            \Async\shutdownOn(\SIGTERM, \SIGINT);
            $this->loop();
        });
    }

    /** Open the listener once; a no-op when the caller supplied one. */
    private function bind(): void
    {
        if ($this->listener !== null) {
            return;
        }
        $errno = 0;
        $errstr = '';
        // php's listener default is 32 — under a burst of connects the queue
        // overflows while the loop is busy, and the kernel answers RST.
        $ctx = $this->context ?? \stream_context_create();
        if (!isset(\stream_context_get_options($ctx)['socket']['backlog'])) {
            \stream_context_set_option($ctx, 'socket', 'backlog', $this->backlog);
        }
        $l = \stream_socket_server($this->addr, $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $ctx);
        if ($l === false) {
            throw new \RuntimeException('Http\\Server: cannot bind ' . $this->addr . ': ' . $errstr);
        }
        \stream_set_blocking($l, false);
        $this->listener = $l;
    }

    /**
     * Registers a hook that runs once when `stop()` is called.
     *
     * This is how a long-lived takeover (such as a WebSocket session) learns that the server is going
     * down. If the server is already stopped, the hook runs immediately. A hook that throws does not
     * keep the others from running.
     *
     * @param $fn  The hook.
     * @return int  An id for `offStop()`.
     */
    // Run $fn once when {@see stop} is called — how a long-lived takeover
    // (a WebSocket session) learns the server is going down. Returns an id
    // for {@see offStop}.
    //
    // A takeover that upgrades DURING or AFTER `stop()` (dispatch was already
    // in flight, or `stop()` raced it) registers into a hook list that will
    // never run again — `stop()` is one-shot. $fn runs INLINE here instead,
    // so a session that checks in late still hears the server is going down.
    public function onStop(\Closure $fn): int
    {
        $this->stopSeq = $this->stopSeq + 1;
        if ($this->stopped) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $this->statErrors = $this->statErrors + 1;
            }
            return $this->stopSeq;
        }
        $this->stopHooks[$this->stopSeq] = $fn;
        return $this->stopSeq;
    }

    /**
     * Removes a hook registered with `onStop()`. An unknown id is ignored.
     *
     * @param $id  The id `onStop()` returned.
     */
    public function offStop(int $id): void
    {
        unset($this->stopHooks[$id]);
    }

    /**
     * Asks the server to wind down.
     *
     * The accept loop stops taking new connections within `acceptWait()` seconds; requests already in
     * flight are joined, not interrupted. Every `onStop()` hook runs once. Calling it again does
     * nothing.
     */
    // Ask the accept loop to wind down. In-flight requests are NOT interrupted:
    // the group joins them, which is what graceful means. Every registered
    // {@see onStop} hook runs once, here — a live takeover's only signal that
    // the server is going down.
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $hooks = $this->stopHooks;
        $this->stopHooks = [];
        foreach ($hooks as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                // One hook's failure must not keep the others from running.
                $this->statErrors = $this->statErrors + 1;
            }
        }
    }

    /**
     * Counters since the server started.
     *
     * - `served`: responses written
     * - `open`: connections open now
     * - `accepted`: connections accepted
     * - `errors`: refused requests, handler exceptions, timeouts and failed writes
     * - `upgraded`: connections handed over by `Response::takeover()`
     * - `stopped`: `1` after `stop()`, else `0`
     *
     * @return array<string,int> served / open / accepted / errors / upgraded / stopped
     */
    public function stats(): array<string, int>
    {
        $out = [];
        $out['served'] = $this->statServed;
        $out['open'] = $this->statOpen;
        $out['accepted'] = $this->statAccepted;
        $out['errors'] = $this->statErrors;
        $out['upgraded'] = $this->statUpgraded;
        $out['stopped'] = $this->stopped ? 1 : 0;
        return $out;
    }

    private function loop(): void
    {
        $this->bind();
        $listener = $this->listener;
        if ($listener === null) {
            return;
        }
        $gate = new \Async\Semaphore($this->maxConnections);
        try {
            \Async\group(function (\Async\TaskGroup $g) use ($listener, $gate) {
                while (!$this->stopped) {
                    // The permit BEFORE the accept: at the ceiling we stop
                    // accepting and the backlog is the queue. Released in the
                    // CHILD's finally — the parent's would run once, at scope exit.
                    $gate->acquire();
                    $conn = \stream_socket_accept($listener, $this->acceptWait);
                    if ($conn === false) {
                        // A timeout (the shutdown tick) or a classified accept
                        // failure the stream layer already backed off for.
                        $gate->release();
                        continue;
                    }
                    $this->statAccepted = $this->statAccepted + 1;
                    \stream_set_blocking($conn, false);
                    $this->noDelay($conn);
                    $g->spawn(function () use ($conn, $gate) {
                        $this->statOpen = $this->statOpen + 1;
                        try {
                            $this->connection($conn);
                        } finally {
                            $this->statOpen = $this->statOpen - 1;
                            $gate->release();
                            \fclose($conn);
                        }
                    });
                    // Let the connection run before the next accept. Without
                    // it one wakeup drains the whole backlog, and with
                    // workers(N) the first worker to wake takes every pending
                    // connection while its siblings idle — measured 1.65 of 4
                    // cores busy on a CPU-bound handler, 3.9 with the yield.
                    \Async\delay(0.0);
                }
            });
        } catch (\Async\CancelledException $e) {
            // Expected: SIGTERM, or an enclosing scope going down.
        }
        if ($this->ownsListener) {
            \fclose($listener);
        }
    }

    /**
     * TCP_NODELAY on every accepted connection, as nginx and Go's net/http
     * do. Without it Nagle holds every small write behind the peer's delayed
     * ACK: a streamed response flushed chunk by chunk left the head on the
     * wire and the chunks in the send buffer for the ACK timer (20–40 ms on
     * Linux loopback), so each flush() cost a round trip of silence and a
     * 5 000-request run took two minutes instead of two seconds.
     */
    private function noDelay(\Resource $conn): void
    {
        $one = \Runtime\Libc\calloc(4, 1);
        if ($one === null) {
            return;
        }
        \poke_i32($one, 0, 1);
        \Runtime\Libc\sys_setsockopt(\__mc_stream_fd($conn), SOL_TCP, TCP_NODELAY, $one, 4);
        \Runtime\Libc\free($one);
    }

    /** One connection: parse, dispatch and answer until it must close. */
    private function connection(\Resource $conn): void
    {
        $buf = new \Buffer\ByteBuffer();
        $out = new Outbox($conn);
        $peer = \stream_socket_get_name($conn, true);
        $remote = $peer === false ? '' : $peer;
        // ONE parser for the connection, reset between messages. Its limits and
        // its buffer do not change, so a fresh object per request was four
        // allocations and a zeroed field block for nothing.
        $parser = new Parser(
            $buf,
            $remote,
            $this->secure,
            $this->maxHeaderBytes,
            $this->maxHeaderCount,
            $this->maxBodySize,
            $conn,
            $this->streamBodies,
            $this->trustedProxies,
            $this->proxyFlags,
            $this->maxInputVars,
            $this->maxFileUploads,
            $this->uploadMaxFilesize,
        );
        $tk = new Takeover();
        try {
            $this->pump($conn, $buf, $out, $parser, $tk);
        } finally {
            // Whatever is queued goes out before the socket does, on EVERY
            // exit — an early return with a response still in the vector is a
            // client waiting forever.
            $out->flush();
        }
    }

    /** The keep-alive loop proper. {@see connection} owns the flush contract. */
    private function pump(\Resource $conn, \Buffer\ByteBuffer $buf, Outbox $out, Parser $parser, Takeover $tk): void
    {
        $handled = 0;
        while (!$this->stopped) {
            $parser->reset();
            // The FIRST read of a request waits idleTimeout (a kept-alive
            // connection may sit silent for a long time and that is not an
            // error); once bytes have arrived the rest of the head is on
            // headerTimeout, so a client that dribbles a head cannot hold a
            // slot open. A silent client is closed silently, as nginx does.
            $first = $buf->isEmpty();
            $this->setTimeout($conn, $first ? $this->idleTimeout : $this->headerTimeout);
            $code = $parser->parse();
            $got = !$first;
            while ($code === Parser::NEED || $code === Parser::CONTINUE_) {
                if ($code === Parser::CONTINUE_) {
                    // `Expect: 100-continue`, and the framing was ACCEPTED —
                    // the parser only asks once the head is framed, so this is
                    // never an invitation to a body already refused. Parsing
                    // resumes where it stopped; the head is not re-read.
                    $out->sendNow("HTTP/1.1 100 Continue\r\n\r\n");
                    $code = $parser->parse();
                    continue;
                }
                $chunk = \fread($conn, self::READ_CHUNK);
                if ($chunk === '') {
                    // A client that connected and said nothing is closed
                    // silently, as nginx does; one that stopped MID-request and
                    // ran out its clock gets a 408.
                    if ($got && $this->timedOut($conn)) {
                        $this->statErrors = $this->statErrors + 1;
                        $this->writeError($out, 408);
                    }
                    return;
                }
                if (!$got) {
                    $got = true;
                    $this->setTimeout($conn, $this->headerTimeout);
                }
                $buf->append($chunk);
                $code = $parser->parse();
            }
            if ($code !== Parser::READY) {
                $this->statErrors = $this->statErrors + 1;
                $this->writeError($out, $code);
                return;
            }
            $req = $parser->request();
            if ($req === null) {
                return;
            }
            $handled = $handled + 1;
            // The SAPI context stays live across the WRITE, not just the
            // handler: a streaming body runs during the write, and inside it
            // headers_sent() has to answer true.
            $this->beginRequest($req);
            try {
                // A per-request scope, so anything a handler binds with
                // Async\Context::withValue() — a correlation id, the
                // authenticated user, a transaction — is visible to every task
                // it spawns and to NOTHING outside this request. The whole
                // serveOne is inside it, not just the handler call, so a
                // streaming body (which runs during the WRITE) sees it too.
                $keep = (bool)\Async\Context::withValue(
                    self::CTX_REQUEST,
                    $req,
                    function () use ($conn, $out, $req, $handled, $tk) {
                        return $this->serveOne($conn, $out, $req, $handled, $tk);
                    },
                );
            } finally {
                \Manticore\Sapi\requestEnd();
            }
            $res = $tk->res;
            if ($res !== null) {
                // After requestEnd(): a takeover runs with no request context.
                $out->flush();
                // pump()'s idle/header read deadline (as short as 0.3s under a
                // tight config) is a REQUEST-parsing concern; a session must
                // not inherit it. 0 clears both directions back to the stream
                // layer's own default (stream_set_timeout, Net.php).
                $this->setTimeout($conn, 0.0);
                $this->runTakeover($conn, $buf, $res);
                return;
            }
            if (!$keep) {
                return;
            }
            // compact(), never clear(): bytes already read past this request are
            // the NEXT one's head, and a pipelining client sends both at once.
            $buf->compact();
            // Nothing else is already in hand to answer, so the queue has
            // nothing left to join: send it. When the buffer DOES still hold a
            // request, holding on collapses the whole pipelined batch into one
            // writev.
            if ($buf->isEmpty()) {
                $out->flush();
            }
        }
    }

    /**
     * One request, from handler to written response, inside a live SAPI
     * context.
     *
     * @return bool whether the connection may be reused
     */
    private function serveOne(\Resource $conn, Outbox $out, Request $req, int $handled, Takeover $tk): bool
    {
        if ($req->hasBody() && !$req->streamed
            && \strncasecmp($req->contentType(), 'multipart/form-data', 19) === 0
            && $req->multipartFailed()) {
            $this->statErrors = $this->statErrors + 1;
            $this->writeError($out, Status::BAD_REQUEST);
            return false;
        }
        $res = $this->dispatch($req);
        if ($res->isTakeover()) {
            // An unread streamed-body byte is a request byte the wire hasn't
            // finished delivering; handing it to the new protocol as ITS
            // first byte is a handler bug (it never read what it asked for),
            // same tier as a takeover that is not a clean 101/1.1.
            $rd = $req->streamed ? $req->stream() : null;
            $bodyLeft = $rd !== null && !$rd->eof();
            if ($res->status !== Status::SWITCHING_PROTOCOLS || $req->version !== '1.1' || $bodyLeft) {
                $this->statErrors = $this->statErrors + 1;
                $this->writeError($out, 500);
                return false;
            }
            $h = $res->headers;
            if ($this->serverName !== '' && !$h->has('server')) {
                $h->add('Server', $this->serverName);
            }
            \Manticore\Sapi\responseSent();
            $out->add(statusLine(101, '1.1') . $h->render());
            $this->statUpgraded = $this->statUpgraded + 1;
            $tk->res = $res;
            return false;
        }
        $keep = $req->isKeepAlive()
            && !$res->wantsClose()
            && !$this->stopped
            && ($this->keepAliveMax === 0 || $handled < $this->keepAliveMax);
        // A streamed body an HTTP/1.0 peer cannot frame has only the close
        // to end it.
        if ($res->isStreaming() && $req->version !== '1.1') {
            $keep = false;
        }
        // A body the handler ignored is still on the wire, and the next
        // request cannot be read until it is off. Drained BEFORE the
        // response, so the peer is not made to wait on a socket we are
        // about to read anyway.
        if ($req->streamed && $keep) {
            $rd = $req->stream();
            if ($rd !== null) {
                $rd->discard();
                if ($rd->over) {
                    $keep = false;
                }
            }
        }
        $this->setTimeout($conn, $this->writeTimeout);
        $keep = $this->writeResponse($conn, $out, $req, $res, $keep);
        $this->statServed = $this->statServed + 1;
        return $keep;
    }

    /** Run a takeover closure; its escape is an error, never the server's end. */
    private function runTakeover(\Resource $conn, \Buffer\ByteBuffer $buf, Response $res): void
    {
        $fn = $res->takeoverFn();
        if ($fn === null) {
            return;
        }
        try {
            $fn($conn, $buf, $this);
        } catch (\Async\CancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->statErrors = $this->statErrors + 1;
        }
    }

    /**
     * Open the request's SAPI context.
     *
     * `header()`, `header_remove()`, `headers_list()`, `http_response_code()`,
     * `setcookie()` and `setrawcookie()` are live in EVERY handler — that is
     * what `Manticore\Sapi\responseBegin()` costs: an empty header block and a status.
     * The superglobals are opt-in (`compat(true)`), because seeding four of
     * them per request for code that never reads them is pure cost.
     */
    private function beginRequest(Request $req): void
    {
        // The upload registry opens BEFORE anything can parse a body: under
        // compat the requestBegin() arguments below run the multipart parse.
        \Manticore\Sapi\uploadsBegin();
        if (!$this->compat) {
            \Manticore\Sapi\responseBegin();
            return;
        }
        // ⚠ Do NOT reimplement the seeding: Manticore\Sapi\requestBegin boxes element by
        // element on purpose (a whole-array store into a cell-element
        // superglobal leaves the elements raw, and `echo $_GET['a']` then
        // prints 2.1E-314).
        // A streamed body is the handler's (Request::stream / multipart):
        // $_POST and $_FILES are not seeded from it.
        \Manticore\Sapi\requestBegin(
            $this->serverVars($req),
            $req->queryArray(),
            $req->streamed ? \Manticore\Sapi\Context::$emptyGpc : $req->postArray(),
            $req->cookies(),
            $req->streamed ? \Manticore\Sapi\Context::$emptyGpc : $req->filesArray(),
        );
    }

    /** @return array<string,string> php's $_SERVER for this request */
    private function serverVars(Request $req): array<string, string>
    {
        $out = [];
        $out['REQUEST_METHOD'] = $req->method;
        $out['REQUEST_URI'] = $req->target;
        $out['SERVER_PROTOCOL'] = 'HTTP/' . $req->version;
        $out['QUERY_STRING'] = $req->queryString;
        // The socket case: `remoteAddr` IS the peer string, `ip:port` with no
        // brackets even for v6 ({@see peerIp}). A forwarded `remoteAddr`
        // carries no port at all — split it here would cut a bare v6 address
        // at its first colon, so it is used as-is instead.
        if ($req->remoteAddr === $req->peerAddr) {
            $colon = \strrpos($req->remoteAddr, ':');
            $out['REMOTE_ADDR'] = peerIp($req->remoteAddr);
            $out['REMOTE_PORT'] = $colon === false ? '' : \substr($req->remoteAddr, $colon + 1);
        } else {
            $out['REMOTE_ADDR'] = $req->remoteAddr;
            $out['REMOTE_PORT'] = '';
        }
        $out['HTTPS'] = $req->secure ? 'on' : '';
        $out['CONTENT_TYPE'] = $req->header('Content-Type');
        $out['CONTENT_LENGTH'] = $req->header('Content-Length');
        $hp = splitHostPort($req->header('Host'));
        $out['SERVER_NAME'] = $hp[0];
        $out['SERVER_PORT'] = $req->forwardedPort !== '' ? $req->forwardedPort
            : ($hp[1] !== '' ? $hp[1] : ($req->secure ? '443' : '80'));
        foreach ($req->headers->all() as $k => $v) {
            $out['HTTP_' . \strtoupper(\str_replace('-', '_', $k))] = $v;
        }
        return $out;
    }

    /** Run the handler, turning any escape into a response rather than a crash. */
    private function dispatch(Request $req): Response
    {
        $capture = $this->captureEcho;
        if ($capture) {
            \ob_start();
        }
        $res = $this->runHandler($req);
        $echoed = '';
        if ($capture) {
            $got = \ob_get_clean();
            if ($got !== false) {
                $echoed = $got;
            }
        }
        return $this->absorb($res, $echoed);
    }

    /** The handler call itself, with every escape turned into a response. */
    private function runHandler(Request $req): Response
    {
        $h = $this->handler;
        try {
            return $h($req);
        } catch (\Async\CancelledException $e) {
            // The scope is going down mid-request. The write suspends, so it
            // has to be shielded, and then the cancellation continues.
            \Async\shield(function () { });
            throw $e;
        } catch (\Throwable $e) {
            $this->statErrors = $this->statErrors + 1;
            $eh = $this->onError;
            if ($eh !== null) {
                try {
                    return $eh($e, $req);
                } catch (\Throwable $e2) {
                    // One level, no recursion: a broken error handler gets the
                    // canned response like anything else.
                }
            }
            return (new Response(500))->text("Internal Server Error\n")->close();
        }
    }

    /**
     * Fold what the handler did AMBIENTLY — `header()`, `setcookie()`,
     * `http_response_code()`, `echo` — into the Response it returned.
     *
     * The rule, and it is the same one three times: the EXPLICIT API wins.
     *
     *  - Headers: the ambient lines go in first, the Response's own apply on
     *    top with replace semantics. `Set-Cookie` is the exception — §5.2
     *    excludes it from joining, and a handler that called `setcookie()` AND
     *    `->cookie()` means both.
     *  - Status: the Response's, if it set one; otherwise
     *    `http_response_code()`'s.
     *  - Body: what was echoed becomes the body ONLY if the Response has none
     *    and is not streaming. Both together is a handler bug — the explicit
     *    body wins and the echoed bytes are dropped, never silently merged.
     */
    private function absorb(Response $res, string $echoed): Response
    {
        $lines = \Manticore\Sapi\responseHeaders();
        if (\count($lines) > 0) {
            $merged = new Headers();
            foreach ($lines as $line) {
                $kv = headerSplit($line);
                if (\count($kv) === 2) {
                    $merged->add($kv[0], $kv[1]);
                }
            }
            foreach ($res->headers->lines() as $line) {
                $kv = headerSplit($line);
                if (\count($kv) !== 2) {
                    continue;
                }
                if (lowerName($kv[0]) === 'set-cookie') {
                    $merged->add($kv[0], $kv[1]);
                } else {
                    $merged->set($kv[0], $kv[1]);
                }
            }
            $res->headers->copyFrom($merged);
        }
        if (!$res->statusWasSet()) {
            $res->status(\Manticore\Sapi\responseStatus());
        }
        // A file body, like a streamed one, is EXPLICIT: echoed bytes are the
        // fallback for a handler that wrote nothing, and `file()` wrote.
        if ($echoed !== '' && $res->getBody() === '' && !$res->isStreaming() && !$res->isFile()) {
            $res->body($echoed);
        }
        return $res;
    }

    /**
     * Head + body in ONE `fwrite` — a literal two-element array is a
     * `writev(2)`, so a response costs one syscall rather than two.
     */
    private function writeResponse(\Resource $conn, Outbox $out, Request $req, Response $res, bool $keep): bool
    {
        if ($res->isFile()) {
            return $this->writeFile($conn, $out, $req, $res, $keep);
        }
        if ($res->isStreaming() && Status::hasBody($res->status)) {
            return $this->writeStreamed($conn, $out, $req, $res, $keep);
        }
        $body = $res->getBody();
        $hasBody = Status::hasBody($res->status);
        if (!$hasBody) {
            $body = '';
        }
        if ($this->compress && $hasBody) {
            $body = $this->gzipBody($req, $res, $body);
        }
        $head = $this->renderHead($res, $req->version, $keep, \strlen($body), $hasBody);
        \Manticore\Sapi\responseSent();
        // HEAD carries the headers of the GET it mirrors — Content-Length
        // included — and no body at all.
        $out->add($head);
        if ($req->method !== 'HEAD') {
            $out->add($body);
        }
        return $keep;
    }

    /**
     * A body produced by a closure, framed as it goes.
     *
     * There is no Content-Length: the length is not knowable until the closure
     * has run, and buffering it to find out is the thing streaming exists to
     * avoid. An HTTP/1.1 peer gets `Transfer-Encoding: chunked`; a 1.0 peer has
     * no such encoding, so the bytes go raw and the CLOSE is the framing (the
     * caller has already forced `Connection: close` for that).
     *
     * @return bool whether the connection may still be reused
     */
    private function writeStreamed(\Resource $conn, Outbox $out, Request $req, Response $res, bool $keep): bool
    {
        $chunked = $req->version === '1.1';
        $h = $res->headers;
        $h->remove('content-length');
        if ($chunked) {
            $h->set('Transfer-Encoding', 'chunked');
        }
        $head = $this->renderHead($res, $req->version, $keep, -1, false);
        // The head is gone the moment it is written — which is what makes
        // headers_sent() honest INSIDE the body closure below, and what stops
        // header() from recording into a block already on the wire.
        \Manticore\Sapi\responseSent();
        // The queue drains BEFORE the body closure runs: from here the bytes
        // go straight to the socket through the writer below, and a queued
        // head would then arrive AFTER them.
        $out->sendNow($head);
        if ($req->method === 'HEAD') {
            return $keep;
        }
        $w = new ChunkedWriter(new \Buffer\Writer($conn), $chunked);
        $fn = $res->bodyFn();
        try {
            $fn($w);
        } catch (\Async\CancelledException $e) {
            $w->end();
            throw $e;
        } catch (\Throwable $e) {
            // The head is already on the wire, so there is no status left to
            // change and no 500 to send — the only honest thing is to end the
            // framing and drop the connection. It does NOT propagate: one
            // handler's mistake must not take the accept loop down with it.
            $this->statErrors = $this->statErrors + 1;
            $w->end();
            return false;
        }
        // The terminator is the SERVER's to write, always: a closure that
        // returned without ending still has to leave the framing valid, or the
        // peer waits for a body that never ends.
        $w->end();
        return $keep;
    }

    /**
     * `Vary: Accept-Encoding`, appended rather than replacing — a cache must
     * key on the header whether or not THIS response came out encoded, or the
     * identity copy it stored is handed to a client that asked for gzip.
     */
    private function addVary(Headers $h): void
    {
        $v = $h->get('vary');
        if ($v === '') {
            $h->add('Vary', 'Accept-Encoding');
        } elseif (\stripos($v, 'accept-encoding') === false && \trim($v) !== '*') {
            $h->set('Vary', $v . ', Accept-Encoding');
        }
    }

    /**
     * The buffered body, gzipped when everything lines up: a compressible type,
     * at least `compressMin` bytes, no encoding already chosen, and a client
     * that asked. `Vary` goes on every compressible-type response either way.
     *
     * Called only under `$this->compress`, which is what keeps `gzencode` out of
     * a program that never turns compression on.
     */
    private function gzipBody(Request $req, Response $res, string $body): string
    {
        $h = $res->headers;
        $ct = $h->get('content-type');
        if ($ct === '' || !compressible($ct)) {
            return $body;
        }
        $this->addVary($h);
        if (\strlen($body) < $this->compressMin || $h->has('content-encoding')
            || !acceptsGzip($req->header('Accept-Encoding'))) {
            return $body;
        }
        $out = \gzencode($body, $this->compressLevel);
        $h->set('Content-Encoding', 'gzip');
        $h->remove('content-length');
        // The encoded bytes are a different representation of the same
        // resource, so a STRONG validator no longer describes what is on the
        // wire (RFC 9110 §8.8.1).
        $etag = $h->get('etag');
        if ($etag !== '' && \strncmp($etag, 'W/', 2) !== 0) {
            $h->set('ETag', 'W/' . $etag);
        }
        return $out;
    }

    /**
     * RFC 9110 §13.2 over a file response the HANDLER left at 200: the status
     * to send, with the file window and the headers settled for it.
     *
     * A handler that set its own status is answering something other than "here
     * is this file" — 201 on an upload, say — and its answer is not the
     * server's to turn into a 304. A non-GET/HEAD is left alone too (v1: php's
     * own `If-Match` / 412 half is not implemented).
     */
    private function conditional(Request $req, Response $res): int
    {
        // The predicate is the STATUS, not `statusWasSet()`: {@see absorb} calls
        // `status()` on every response that did not set one, to let an ambient
        // `http_response_code()` win, and `status()` marks the flag — so by the
        // time a response is written the flag is true for all of them.
        if ($res->status !== 200 || ($req->method !== 'GET' && $req->method !== 'HEAD')) {
            return $res->status;
        }
        $h = $res->headers;
        $etag = $h->get('etag');
        $inm = $req->header('If-None-Match');
        $notModified = false;
        if ($inm !== '') {
            // `If-None-Match` present WINS: a date is the weaker validator and
            // §13.1.3 says it is only consulted when there is no entity tag.
            $notModified = $etag !== '' && etagMatches($inm, $etag);
        } else {
            $ims = $req->header('If-Modified-Since');
            if ($ims !== '') {
                $since = \strtotime($ims);
                $notModified = $since !== false && $res->fileMtime() <= $since;
            }
        }
        if ($notModified) {
            $h->remove('content-type');
            $h->remove('content-length');
            $h->remove('accept-ranges');
            $res->setFileWindow(0, 0);
            return Status::NOT_MODIFIED;
        }
        $range = $req->header('Range');
        if ($range === '') {
            return $res->status;
        }
        $ifRange = $req->header('If-Range');
        if ($ifRange !== '') {
            // §13.1.5: an entity tag in `If-Range` must match STRONGLY. Ours are
            // all weak, so a tag never narrows — only the exact date does.
            $ok = \strpos($ifRange, '"') !== false
                ? ($etag !== '' && $ifRange === $etag && \strncmp($etag, 'W/', 2) !== 0)
                : (\strtotime($ifRange) === $res->fileMtime());
            if (!$ok) {
                return $res->status;
            }
        }
        $win = parseRange($range, $res->fileSize());
        if ($win === null) {
            $h->set('Content-Range', 'bytes */' . $res->fileSize());
            $h->remove('content-type');
            $res->setFileWindow(0, 0);
            return Status::RANGE_NOT_SATISFIABLE;
        }
        if ($win[0] < 0) {
            return $res->status;
        }
        $h->set('Content-Range', 'bytes ' . $win[0] . '-' . $win[1] . '/' . $res->fileSize());
        $res->setFileWindow($win[0], $win[1] - $win[0] + 1);
        return Status::PARTIAL_CONTENT;
    }

    /**
     * A file body: the head, then the bytes straight from the page cache.
     *
     * `sendfile(2)` on a plain socket — the bytes never enter the process. A
     * TLS connection has to encrypt them, so it takes the `fread` loop, and so
     * does a host whose `__mc_sendfile` answers -3. Either way the fd is closed
     * on every exit: the Response opened it and this is where it dies.
     */
    private function writeFile(\Resource $conn, Outbox $out, Request $req, Response $res, bool $keep): bool
    {
        // The precompressed sibling is chosen BEFORE the conditionals: it
        // changes the ETag and the length those answer with.
        if ($this->compress && compressible($res->headers->get('content-type'))) {
            $this->addVary($res->headers);
            if (!$res->headers->has('content-encoding')
                && acceptsGzip($req->header('Accept-Encoding'))) {
                $res->useGzSibling();
            }
        }
        $res->status = $this->conditional($req, $res);
        // AFTER the sibling swap: `useGzSibling()` closes the Resource it
        // replaces, so a handle taken before it would be a closed FILE*.
        $in = $res->fileResource();
        if ($in === null) {
            return $keep;
        }
        $hasBody = Status::hasBody($res->status) && $res->fileLength() > 0;
        $h = $res->headers;
        $h->remove('content-length');
        $head = $this->renderHead($res, $req->version, $keep, $hasBody ? $res->fileLength() : 0, $hasBody);
        \Manticore\Sapi\responseSent();
        // The queue drains BEFORE the first sendfile: a pipelined response
        // still sitting in the Outbox would otherwise arrive after the file.
        $out->sendNow($head);
        if (!$hasBody || $req->method === 'HEAD') {
            \fclose($in);
            return $keep;
        }
        $off = $res->fileOffset();
        $left = $res->fileLength();
        $ok = true;
        $useSendfile = $conn->kind === \Resource::KIND_SOCKET;
        while ($left > 0) {
            if ($useSendfile) {
                $n = \__mc_sendfile($conn, $in, $off, $left);
                if ($n === -3) {
                    $useSendfile = false;
                    continue;
                }
                if ($n === -2) {
                    if (\__mc_wait_write($conn) === 0) {
                        $ok = false;
                        break;
                    }
                    continue;
                }
                if ($n < 0) {
                    $ok = false;
                    break;
                }
                $off = $off + $n;
                $left = $left - $n;
                continue;
            }
            \fseek($in, $off);
            $chunk = \fread($in, $left < 65536 ? $left : 65536);
            if ($chunk === '') {
                $ok = false;
                break;
            }
            $w = \fwrite($conn, $chunk);
            if ($w !== \strlen($chunk)) {
                $ok = false;
                break;
            }
            $off = $off + $w;
            $left = $left - $w;
        }
        \fclose($in);
        if (!$ok) {
            $this->statErrors = $this->statErrors + 1;
            return false;
        }
        return $keep;
    }

    private function renderHead(Response $res, string $version, bool $keep, int $len, bool $hasBody): string
    {
        $h = $res->headers;
        if ($hasBody && $len >= 0 && !$h->has('content-length')) {
            $h->add('Content-Length', (string)$len);
        }
        if (!$h->has('date')) {
            $now = \time();
            if ($now !== self::$dateSec) {
                self::$dateSec = $now;
                self::$dateStr = httpDate($now);
            }
            $h->add('Date', self::$dateStr);
        }
        if ($this->serverName !== '' && !$h->has('server')) {
            $h->add('Server', $this->serverName);
        }
        // add(), not set(), on every line above: set() has to REBUILD the wire
        // list to drop any earlier line with that name, and each of these has
        // just been proven absent. Connection is the one that may already be
        // there, so it keeps replace semantics.
        // `Connection: keep-alive` is NOT sent on 1.1 — persistence is the
        // version's default, so the header says nothing and costs 24 bytes on
        // every response (nginx omits it for the same reason). 1.0 inverts the
        // default, so there it has to be spelled out.
        $conn = '';
        if (!$keep) {
            $conn = 'close';
        } elseif ($version === '1.0') {
            $conn = 'keep-alive';
        }
        if ($conn !== '') {
            if ($h->has('connection')) {
                $h->set('Connection', $conn);
            } else {
                $h->add('Connection', $conn);
            }
        } elseif ($h->has('connection')) {
            // A handler that set one itself, on a connection we are keeping:
            // its value would contradict what we are about to do.
            $h->remove('connection');
        }
        return statusLine($res->status, $version) . $h->render();
    }

    /**
     * A refusal the handler never sees: one PRECOMPUTED constant, one write.
     *
     * A `match` over literals, not a concat, and that is the point — every one
     * of these is reachable by an unauthenticated peer, and an error path that
     * allocates is an error path a client can turn into a cost. The strings sit
     * in .rodata; the arm is a select.
     */
    private function writeError(Outbox $out, int $code): void
    {
        $out->sendNow(match ($code) {
            400 => "HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            405 => "HTTP/1.1 405 Method Not Allowed\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            408 => "HTTP/1.1 408 Request Timeout\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            411 => "HTTP/1.1 411 Length Required\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            413 => "HTTP/1.1 413 Content Too Large\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            414 => "HTTP/1.1 414 URI Too Long\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            417 => "HTTP/1.1 417 Expectation Failed\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            431 => "HTTP/1.1 431 Request Header Fields Too Large\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            500 => "HTTP/1.1 500 Internal Server Error\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            501 => "HTTP/1.1 501 Not Implemented\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            503 => "HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            505 => "HTTP/1.1 505 HTTP Version Not Supported\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            default => 'HTTP/1.1 ' . $code . " Error\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
        });
    }

    /**
     * Bound the next operation on this connection.
     *
     * Seconds AND microseconds, because the fractional part is the whole
     * setting for a test and for anyone who wants a sub-second idle window: an
     * `(int)` cast alone turns 0.3 into 0, which php reads as "no timeout".
     */
    private function setTimeout(\Resource $conn, float $seconds): void
    {
        $whole = (int)$seconds;
        $micro = (int)(($seconds - $whole) * 1000000.0);
        \stream_set_timeout($conn, $whole, $micro);
    }

    /** Whether the last short read was a TIMEOUT rather than a close. */
    private function timedOut(\Resource $conn): bool
    {
        $meta = \stream_get_meta_data($conn);
        return isset($meta['timed_out']) && $meta['timed_out'];
    }
}

}
