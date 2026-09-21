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
 * The reason phrase for a status code, or '' when we do not know it.
 *
 * A `match`, and a plain one: the table is written once for the whole process,
 * so the arms cost nothing per request. This is also why {@see Status} is a
 * class of `const int` rather than a backed enum — 42 enum cases would mean
 * `from`/`tryFrom`/`cases()` arms in the IR of every program that serves HTTP,
 * to produce a value that goes on the wire as an int.
 */
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

/** Is `$ip` inside `$cidr`? A bare address is /32 or /128. Malformed → false. */
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

/** The first comma-separated element, trimmed. @internal */
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

/** "0" or a digit run with no leading zero, optionally negated; ≤ 18 digits. @internal */
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

/** A chunk source over a string. @internal */
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
 * The nine methods RFC 9110 defines, as a typed lens over the wire token.
 *
 * {@see Request::$method} stays a raw `string` and this enum is optional on
 * purpose: WebDAV's `PROPFIND`, a CDN's `PURGE` and anything else a handler is
 * entitled to answer have no case here, and a `?Method` on the hot path would
 * put a null check in front of every route test. `methodEnum()` is for the
 * handler that wants exhaustive `match`; `$method === 'GET'` stays legal.
 */
enum Method: string
{
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Options = 'OPTIONS';
    case Trace = 'TRACE';
    case Connect = 'CONNECT';

    /** No side effects expected of the origin (§9.2.1). */
    public function isSafe(): bool
    {
        return $this === Method::Get || $this === Method::Head
            || $this === Method::Options || $this === Method::Trace;
    }

    /** Repeating it must read the same as doing it once (§9.2.2). */
    public function isIdempotent(): bool
    {
        return $this->isSafe() || $this === Method::Put || $this === Method::Delete;
    }

    /**
     * Whether a REQUEST body is meaningful. Not the same as "may carry one":
     * a GET with a body is legal on the wire and forbidden to mean anything, so
     * the framing rules read this to decide whether a missing Content-Length is
     * a 411 or simply an empty request.
     */
    public function allowsBody(): bool
    {
        return $this === Method::Post || $this === Method::Put || $this === Method::Patch;
    }
}

/**
 * Status codes, and the two predicates the response path needs.
 *
 * A class of `const int`, not a backed enum, and the reason is IR volume: 42
 * enum cases put `from`/`tryFrom`/`cases()` arms into every program that serves
 * HTTP, to model a value that is an `int` on the wire and an `int` in every
 * comparison. {@see text} delegates to {@see reason}, so the table is written
 * once for the whole process.
 */
final class Status
{
    public const CONTINUE = 100;
    public const SWITCHING_PROTOCOLS = 101;
    public const OK = 200;
    public const CREATED = 201;
    public const ACCEPTED = 202;
    public const NO_CONTENT = 204;
    public const PARTIAL_CONTENT = 206;
    public const MOVED_PERMANENTLY = 301;
    public const FOUND = 302;
    public const SEE_OTHER = 303;
    public const NOT_MODIFIED = 304;
    public const TEMPORARY_REDIRECT = 307;
    public const PERMANENT_REDIRECT = 308;
    public const BAD_REQUEST = 400;
    public const UNAUTHORIZED = 401;
    public const FORBIDDEN = 403;
    public const NOT_FOUND = 404;
    public const METHOD_NOT_ALLOWED = 405;
    public const NOT_ACCEPTABLE = 406;
    public const REQUEST_TIMEOUT = 408;
    public const CONFLICT = 409;
    public const GONE = 410;
    public const LENGTH_REQUIRED = 411;
    public const PRECONDITION_FAILED = 412;
    public const CONTENT_TOO_LARGE = 413;
    public const URI_TOO_LONG = 414;
    public const UNSUPPORTED_MEDIA_TYPE = 415;
    public const RANGE_NOT_SATISFIABLE = 416;
    public const EXPECTATION_FAILED = 417;
    public const MISDIRECTED_REQUEST = 421;
    public const UNPROCESSABLE_CONTENT = 422;
    public const TOO_EARLY = 425;
    public const UPGRADE_REQUIRED = 426;
    public const PRECONDITION_REQUIRED = 428;
    public const TOO_MANY_REQUESTS = 429;
    public const REQUEST_HEADER_FIELDS_TOO_LARGE = 431;
    public const INTERNAL_SERVER_ERROR = 500;
    public const NOT_IMPLEMENTED = 501;
    public const BAD_GATEWAY = 502;
    public const SERVICE_UNAVAILABLE = 503;
    public const GATEWAY_TIMEOUT = 504;
    public const HTTP_VERSION_NOT_SUPPORTED = 505;

    /** The reason phrase, or '' for a code we do not name. */
    public static function text(int $code): string
    {
        return reason($code);
    }

    public static function isRedirect(int $code): bool
    {
        return $code === 301 || $code === 302 || $code === 303
            || $code === 307 || $code === 308;
    }

    /**
     * Whether a response with this code may carry a body at all (§6.4.1).
     * 1xx, 204 and 304 may not — not even a `Content-Length: 0`.
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
 * Which forwarded headers a trusted proxy may set. `const int` flags, not an
 * enum, for the same reason {@see Status} is not one.
 *
 * `FORWARDED` is NOT in `ALL` — opt in explicitly. A proxy that merely passes
 * a client-supplied `Forwarded:` header through is exactly as unsafe as
 * trusting an arbitrary `X-Forwarded-*`; RFC 7239 support is offered, not
 * defaulted.
 */
final class Proxy
{
    public const FOR = 1;
    public const PROTO = 2;
    public const HOST = 4;
    public const PORT = 8;
    public const FORWARDED = 16;
    public const ALL = 15;
}

/**
 * A header block: a lookup by lowercased name, and the wire lines in order.
 *
 * Two structures rather than one because the two questions are different. A
 * handler asks "what is the Content-Type" — one hash probe, case-insensitive,
 * repeats comma-joined the way §5.2 says a recipient may. The wire asks "what
 * exactly do I send" — order preserved, and `Set-Cookie` repeated rather than
 * joined, because joining it is the one case §5.2 explicitly excludes.
 *
 * ⚠ Both properties carry a DECLARED element type, and that is load-bearing:
 * `private array $map = []` types `assoc[string,unknown]`, and every index read
 * off it then comes back as a raw pointer instead of a string.
 */
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

    /** Build from wire lines, as they came off the head. */
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

    public function has(string $n): bool
    {
        return isset($this->map[lowerName($n)]);
    }

    public function get(string $n, string $default = ''): string
    {
        $k = lowerName($n);
        if (!isset($this->map[$k])) {
            return $default;
        }
        return $this->map[$k];
    }

    /** The value as an int, or $default when absent or not all digits. */
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

    /** Replace every occurrence of $n with a single line. */
    public function set(string $n, string $v): void
    {
        $k = lowerName($n);
        $this->dropLines($k);
        $this->map[$k] = $v;
        $this->block .= $n . ': ' . $v . "\r\n";
    }

    /**
     * Append a line, keeping any that are already there. The lookup value is
     * comma-joined; the wire keeps both lines, which is what `Set-Cookie` needs.
     */
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

    public function remove(string $n): void
    {
        $k = lowerName($n);
        unset($this->map[$k]);
        $this->dropLines($k);
    }

    /** Distinct field names. */
    public function count(): int
    {
        return \count($this->map);
    }

    /** @return array<string,string> lowercased name => value */
    public function all(): array<string, string>
    {
        return $this->map;
    }

    /**
     * @return array<int,string> the wire lines, in order
     *
     * Split on demand. Nothing on the response path calls this — render()
     * hands the block over whole — so the array only ever exists for a caller
     * that genuinely wants one (the SAPI absorption, a test).
     */
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

    /** The wire block, terminating CRLF included. */
    public function render(): string
    {
        return $this->block . "\r\n";
    }

    /** Drop every field. */
    public function clear(): void
    {
        $this->map = self::$emptyMap;
        $this->block = '';
    }

    /**
     * Become a copy of `$o`.
     *
     * Through the public wire lines rather than the private fields, so repeats
     * and their order survive exactly — which is what the SAPI absorption needs
     * (`Set-Cookie` accumulates, everything else replaces).
     */
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
 * One uploaded file, php's $_FILES row as an object. `tmpName` is '' unless
 * `error` is ERR_OK; the temp file is the request's and goes away with it
 * unless {@see moveTo} claims it first.
 */
final class UploadedFile
{
    public const ERR_OK = 0;
    public const ERR_INI_SIZE = 1;
    public const ERR_FORM_SIZE = 2;
    public const ERR_PARTIAL = 3;
    public const ERR_NO_FILE = 4;
    public const ERR_NO_TMP_DIR = 6;
    public const ERR_CANT_WRITE = 7;

    private bool $moved = false;

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

    public function isValid(): bool
    {
        return $this->error === self::ERR_OK && !$this->moved && $this->tmpName !== '';
    }

    /** rename(2), falling back to copy+unlink across devices. */
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
            if ($this->pName === 'MAX_FILE_SIZE' && \ctype_digit($this->pValue)) {
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
 * One part of a streamed multipart body ({@see Request::multipart}): a bounded
 * view over the parser's current part. Its bytes are read from the wire as the
 * handler asks for them and are retained nowhere else; once the generator
 * moves on, {@see read} answers ''.
 */
final class Part
{
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

    /** Up to $max bytes of this part; '' once its delimiter is reached. */
    public function read(int $max): string
    {
        return $this->m->readPart($max, $this->seq);
    }

    /** The rest of this part as one string — the consumer's memory, uncapped. */
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
 * One parsed request. Immutable to the handler.
 *
 * The public surface is `readonly`; the two memo fields and the bitfield beside
 * them are not, and that is deliberate rather than a loophole. PHP freezes only
 * the properties declared `readonly`, so nothing a handler can reach changes —
 * but a request whose query is scanned afresh for every `query()` call scans it
 * five times for a handler reading five parameters. `$parsed` keeps the
 * already-done test to a single load.
 */
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

    public function header(string $n, string $d = ''): string
    {
        return $this->headers->get($n, $d);
    }

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

    /** @return array<string,string> flat, last-wins */
    public function queries(): array<string, string>
    {
        if (($this->parsed & self::P_QUERY) === 0) {
            $this->queryCache = parseQuery($this->queryString);
            $this->parsed = $this->parsed | self::P_QUERY;
        }
        return $this->queryCache;
    }

    /** php's $_GET shape: nested (`a[]`, `a[b][c]`), last-wins, max_input_vars-capped. @return array<int|string, mixed> */
    public function queryArray(): array<int|string, mixed>
    {
        if (($this->parsed & self::P_QUERY_NESTED) === 0) {
            $this->queryNested = parseQueryNested($this->queryString, $this->maxInputVars);
            $this->parsed = $this->parsed | self::P_QUERY_NESTED;
        }
        return $this->queryNested;
    }

    /** php's $_POST shape from a urlencoded form, or a multipart body's fields. @return array<int|string, mixed> */
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

    public function multipartFailed(): bool
    {
        if ($this->streamed) {
            return false;
        }
        $this->ensureMultipart('multipartFailed');
        return $this->multipartFailed;
    }

    /**
     * The parts of a STREAMED multipart body ({@see Server::streamBodies}),
     * one {@see Part} at a time, read from the wire as the handler consumes
     * them — no temp file, nothing buffered beyond one read. The body is the
     * generator's: {@see allFiles}/{@see postArray} refuse a streamed
     * multipart body, and a buffered one is theirs (this throws).
     *
     * @return \Generator<int, Part>
     */
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

    /** Every file part, in wire order. @return array<int, UploadedFile> */
    public function allFiles(): array<int, UploadedFile>
    {
        $this->ensureMultipart('allFiles');
        $m = $this->multipart;
        return $m === null ? self::$noFiles : $m->files();
    }

    /** The first file per field name. @return array<string, UploadedFile> */
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
     * php's $_FILES: six columns, nested names transposed per column
     * (`f[]`×2 → `$_FILES['f']['name'] = [0 => …, 1 => …]`, `u[avatar]` →
     * `$_FILES['u']['name']['avatar']`). A part with filename= but no name= is
     * php's anonymous upload: rfc1867 files it under a running int, `$_FILES[0]`.
     * @return array<int|string, mixed>
     */
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

    /** @return array<string,string> */
    public function cookies(): array<string, string>
    {
        if (($this->parsed & self::P_COOKIE) === 0) {
            $this->cookieCache = parseCookies($this->headers->get('cookie'));
            $this->parsed = $this->parsed | self::P_COOKIE;
        }
        return $this->cookieCache;
    }

    /** The buffered body. '' for a streamed one — use {@see stream}. */
    public function body(): string
    {
        return $this->bodyRaw;
    }

    /** The body reader for a streamed body, null when it was buffered. */
    public function stream(): ?\Buffer\Reader
    {
        return $this->reader;
    }

    public function hasBody(): bool
    {
        return $this->streamed || $this->bodyRaw !== '';
    }

    /**
     * The declared `Content-Length`, or -1 when the request did not declare one
     * (a chunked body never does — its size is only known once it is read).
     */
    public function contentLength(): int
    {
        $v = $this->headers->get('content-length');
        if ($v === '') {
            return -1;
        }
        return contentLength($v);
    }

    /** The media type without its parameters, lowercased. */
    public function contentType(): string
    {
        $v = $this->headers->get('content-type');
        $s = \strpos($v, ';');
        if ($s !== false) {
            $v = \substr($v, 0, $s);
        }
        return \strtolower(\trim($v));
    }

    /** The method as a {@see Method}, or null for one outside RFC 9110. */
    public function methodEnum(): ?Method
    {
        return Method::tryFrom($this->method);
    }

    public function is(Method $m): bool
    {
        return $this->method === $m->value;
    }

    /** Whether the connection may be reused after this message. */
    public function isKeepAlive(): bool
    {
        return !connClose($this->headers->get('connection'), $this->version);
    }
}

/**
 * A response under construction: mutable, fluent, and the handler's return value.
 *
 * Mutable rather than `with*()`-immutable because a handler builds exactly one
 * of these and throws it over the wall. PSR-7's immutability buys substitution
 * across middleware — and middleware is explicitly not in this layer; a package
 * that wants PSR-7 wraps this.
 *
 * Every setter answers `Response`, not `static`: the class is `final`.
 */
final class Response
{
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

    private bool $close = false;

    public function __construct(int $status = 200, string $body = '')
    {
        $this->headers = new Headers();
        if ($status !== 200) {
            $this->status = $status;
            $this->statusSet = true;
        }
        $this->body = $body;
    }

    public function status(int $c): Response
    {
        $this->status = $c;
        $this->statusSet = true;
        return $this;
    }

    /** Replace this header. */
    public function header(string $n, string $v): Response
    {
        $this->headers->set($n, $v);
        return $this;
    }

    /** Append a header, keeping any already set. */
    public function addHeader(string $n, string $v): Response
    {
        $this->headers->add($n, $v);
        return $this;
    }

    public function withoutHeader(string $n): Response
    {
        $this->headers->remove($n);
        return $this;
    }

    public function type(string $ct): Response
    {
        $this->headers->set('Content-Type', $ct);
        return $this;
    }

    public function text(string $s): Response
    {
        $this->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $this->body = $s;
        return $this;
    }

    public function html(string $s): Response
    {
        $this->headers->set('Content-Type', 'text/html; charset=utf-8');
        $this->body = $s;
        return $this;
    }

    public function body(string $b): Response
    {
        $this->body = $b;
        return $this;
    }

    /** Append to the body — the in-place amortized `.=`, not a fresh string. */
    public function write(string $b): Response
    {
        $this->body .= $b;
        return $this;
    }

    /**
     * Produce the body from a closure taking a {@see \Buffer\Writer}.
     *
     * The response then goes out chunked: the length is not knowable before the
     * closure has run, and buffering it to find out is the thing streaming
     * exists to avoid.
     */
    public function stream(callable $fn): Response
    {
        $this->bodyFn = $fn;
        return $this;
    }

    public function redirect(string $loc, int $code = 302): Response
    {
        $this->headers->set('Location', $loc);
        return $this->status($code);
    }

    /**
     * Queue a `Set-Cookie`, rendered by the same function `setcookie()` uses —
     * one renderer, php's attribute order. The value is percent-encoded, as
     * `setcookie()` encodes it.
     */
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

    /** Close the connection after this response. */
    public function close(): Response
    {
        $this->close = true;
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isStreaming(): bool
    {
        return $this->bodyFn !== null;
    }

    /** @internal the Server's handle on the streaming closure */
    public function bodyFn(): mixed
    {
        return $this->bodyFn;
    }

    public function wantsClose(): bool
    {
        return $this->close;
    }

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
 * The Request being served on this flow, or null outside one.
 *
 * The ambient reader for code too deep to be handed the Request — a logger, a
 * repository, an error page. It reads the per-request `Async\Context` scope
 * {@see Server} opens, so it is visible inside any task the handler spawns and
 * in nothing outside that request, which is what makes it safe with many
 * requests in flight at once. Bind your own the same way:
 * `Async\Context::withValue('app.user', $u, fn() => …)`.
 */
function request(): ?Request
{
    $v = \Async\Context::value(Server::CTX_REQUEST);
    if ($v instanceof Request) {
        return $v;
    }
    return null;
}

/**
 * The writer a streaming response body is handed.
 *
 * Chunked framing lives HERE rather than in `Buffer\Writer`, and deliberately:
 * `Buffer\` is parsed before `Http\`, so a buffer that knew about
 * `Transfer-Encoding` would invert the dependency. This composes the plain
 * writer instead.
 *
 * `$framed` is false for an HTTP/1.0 peer, which has no chunked encoding: the
 * bytes go out raw and the connection close IS the framing, which is why the
 * Server forces `Connection: close` on that path.
 */
final class ChunkedWriter
{
    private \Buffer\Writer $w;
    private bool $framed;
    private bool $ended = false;

    public function __construct(\Buffer\Writer $w, bool $framed = true)
    {
        $this->w = $w;
        $this->framed = $framed;
    }

    /** Write one chunk. An empty write is dropped — a zero-length chunk is the
     *  TERMINATOR, so sending one here would end the body early. */
    public function write(string $s): void
    {
        if ($s === '') {
            return;
        }
        $this->w->write($this->framed ? chunkFrame($s) : $s);
    }

    /** Push what is queued to the socket — what makes streaming observable. */
    public function flush(): void
    {
        $this->w->flush();
    }

    /** The terminating zero-length chunk. Idempotent; the Server calls it. */
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

    public function bytesWritten(): int
    {
        return $this->w->bytesWritten();
    }

    public function isChunked(): bool
    {
        return $this->framed;
    }
}

/**
 * An HTTP/1.1 server: `(new Server($addr))->serve($handler)`, where `$handler`
 * is `callable(Request): Response`.
 *
 * Written on ORDINARY blocking-style stream I/O — `stream_socket_accept`,
 * `fread`, `fwrite`, `fclose` — never `Async\read`/`Async\write`. Inside
 * `Async\async()` every one of those routes its would-block through the
 * netpoller and suspends the fiber, so this reads like a blocking server and
 * runs like an evented one. It also means a `tls://` listener works: the raw
 * async path carries neither buffering nor TLS.
 *
 * Concurrency is a per-connection task under one {@see \Async\TaskGroup}, so
 * the scope cannot close while a request is in flight and cancelling it
 * cancels every connection. The permit is taken BEFORE the accept: at the
 * ceiling this worker simply stops accepting and the queue stays in the
 * kernel's backlog, which is what backpressure means for a server.
 */
final class Server
{
    /** Bytes asked of the socket per read. */
    private const READ_CHUNK = 8192;

    /** The Async\Context key the current Request is bound under. {@see request} */
    public const CTX_REQUEST = 'http.request';

    private string $addr;
    private mixed $context;
    private ?\Resource $listener = null;
    private bool $ownsListener = true;

    private mixed $handler = null;
    private mixed $onError = null;

    private int $workerCount = 0;
    private int $maxConnections = 256;
    private bool $compat = false;
    private bool $captureEcho = true;
    private float $idleTimeout = 5.0;
    private float $headerTimeout = 10.0;
    private float $writeTimeout = 30.0;
    private int $maxHeaderBytes = 16384;
    private int $maxHeaderCount = 100;
    private int $maxBodySize = 8388608;
    private bool $streamBodies = false;
    /** Requests per connection before it is closed. 100 tore a connection
     *  down and rebuilt it — accept, close, and a TLS handshake if any — every
     *  hundred requests; nginx's equivalent default is 1000. It is a DoS knob,
     *  not a correctness one. */
    private int $keepAliveMax = 1000;
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

    public function __construct(string $addr = 'tcp://127.0.0.1:8080', mixed $context = null)
    {
        $this->addr = $addr;
        $this->context = $context;
        $this->secure = \strncmp($addr, 'tls://', 6) === 0 || \strncmp($addr, 'ssl://', 6) === 0;
    }

    /**
     * Serve an ALREADY-BOUND listener.
     *
     * What socket activation needs, and what makes this testable: a case binds
     * its own port (scanning for a free one), hands the resource over and keeps
     * the client side in the same process. The Server does not close a listener
     * it did not open.
     */
    public static function onListener(\Resource $listener, bool $secure = false): Server
    {
        $s = new Server('', null);
        $s->listener = $listener;
        $s->ownsListener = false;
        $s->secure = $secure;
        return $s;
    }

    public function workers(int $n): Server { $this->workerCount = $n; return $this; }
    public function maxConnections(int $n): Server { $this->maxConnections = $n < 1 ? 1 : $n; return $this; }
    public function compat(bool $on): Server { $this->compat = $on; return $this; }
    public function captureEcho(bool $on): Server { $this->captureEcho = $on; return $this; }
    public function idleTimeout(float $s): Server { $this->idleTimeout = $s; return $this; }
    public function headerTimeout(float $s): Server { $this->headerTimeout = $s; return $this; }
    public function writeTimeout(float $s): Server { $this->writeTimeout = $s; return $this; }
    public function maxHeaderBytes(int $n): Server { $this->maxHeaderBytes = $n; return $this; }
    public function maxHeaderCount(int $n): Server { $this->maxHeaderCount = $n; return $this; }
    public function maxBodySize(int $n): Server { $this->maxBodySize = $n; return $this; }
    public function streamBodies(bool $on): Server { $this->streamBodies = $on; return $this; }
    public function keepAliveMax(int $n): Server { $this->keepAliveMax = $n; return $this; }
    /** '' omits the `Server:` header entirely. */
    public function serverName(string $s): Server { $this->serverName = $s; return $this; }
    public function acceptWait(float $s): Server { $this->acceptWait = $s; return $this; }
    /** php's `max_input_vars` for $_GET / $_POST (1000, like php.ini's default). */
    public function maxInputVars(int $n): Server { $this->maxInputVars = $n < 1 ? 1 : $n; return $this; }
    /** php's `max_file_uploads` (20). */
    public function maxFileUploads(int $n): Server { $this->maxFileUploads = $n < 0 ? 0 : $n; return $this; }
    /** php's per-file `upload_max_filesize` (2 MiB). */
    public function uploadMaxFilesize(int $n): Server { $this->uploadMaxFilesize = $n < 0 ? 0 : $n; return $this; }
    /**
     * php's `post_max_size`. Reserved — not enforced in either mode: a
     * buffered body is already bounded by {@see maxBodySize}, and a streamed
     * one's field bytes are the handler's ({@see Part::readAll}).
     */
    public function postMaxSize(int $n): Server { $this->postMaxSize = $n < 0 ? 0 : $n; return $this; }
    /** `callable(\Throwable, ?Request): Response` */
    public function onError(callable $fn): Server { $this->onError = $fn; return $this; }

    /**
     * Peers whose `X-Forwarded-*` / `Forwarded` headers are believed. Off
     * unless called. A malformed entry throws here, at configuration time.
     *
     * @param array<int, string> $cidrs
     */
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
     * Run until {@see stop} (or cancellation). `$handler` is
     * `callable(Request): Response`.
     *
     * Reentrant on purpose: called from INSIDE an `Async\async()` scope it just
     * runs the loop as a task of that scope — which is what lets one process
     * host both the server and its client, i.e. what makes the suite offline.
     * Called from outside it opens its own scope and installs the shutdown
     * signals, which is what an application wants.
     */
    public function serve(callable $handler): void
    {
        $this->handler = $handler;
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
        $l = $this->context === null
            ? \stream_socket_server($this->addr, $errno, $errstr)
            : \stream_socket_server($this->addr, $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $this->context);
        if ($l === false) {
            throw new \RuntimeException('Http\\Server: cannot bind ' . $this->addr . ': ' . $errstr);
        }
        \stream_set_blocking($l, false);
        $this->listener = $l;
    }

    /**
     * Ask the accept loop to wind down. In-flight requests are NOT interrupted:
     * the group joins them, which is what graceful means.
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /** @return array<string,int> served / open / accepted / errors / stopped */
    public function stats(): array<string, int>
    {
        $out = [];
        $out['served'] = $this->statServed;
        $out['open'] = $this->statOpen;
        $out['accepted'] = $this->statAccepted;
        $out['errors'] = $this->statErrors;
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
                }
            });
        } catch (\Async\CancelledException $e) {
            // Expected: SIGTERM, or an enclosing scope going down.
        }
        if ($this->ownsListener) {
            \fclose($listener);
        }
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
        try {
            $this->pump($conn, $buf, $out, $parser);
        } finally {
            // Whatever is queued goes out before the socket does, on EVERY
            // exit — an early return with a response still in the vector is a
            // client waiting forever.
            $out->flush();
        }
    }

    /** The keep-alive loop proper. {@see connection} owns the flush contract. */
    private function pump(\Resource $conn, \Buffer\ByteBuffer $buf, Outbox $out, Parser $parser): void
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
                    function () use ($conn, $out, $req, $handled) {
                        return $this->serveOne($conn, $out, $req, $handled);
                    },
                );
            } finally {
                \Manticore\Sapi\requestEnd();
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
    private function serveOne(\Resource $conn, Outbox $out, Request $req, int $handled): bool
    {
        if ($req->hasBody() && !$req->streamed
            && \strncasecmp($req->contentType(), 'multipart/form-data', 19) === 0
            && $req->multipartFailed()) {
            $this->statErrors = $this->statErrors + 1;
            $this->writeError($out, Status::BAD_REQUEST);
            return false;
        }
        $res = $this->dispatch($req);
        $keep = $req->isKeepAlive()
            && !$res->wantsClose()
            && !$this->stopped
            && $handled < $this->keepAliveMax;
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
        if ($echoed !== '' && $res->getBody() === '' && !$res->isStreaming()) {
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
        if ($res->isStreaming() && Status::hasBody($res->status)) {
            return $this->writeStreamed($conn, $out, $req, $res, $keep);
        }
        $body = $res->getBody();
        $hasBody = Status::hasBody($res->status);
        if (!$hasBody) {
            $body = '';
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
