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
 * Flat because that is what `prelude/sapi.php` already contracts for the GPC
 * arrays it seeds: nested `?a[]=1` waits for the same epic as multipart. `+` is
 * a space here (urldecode, not rawurldecode) — that is the form-encoding rule,
 * and it is why the query and the path decode differently.
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

}
