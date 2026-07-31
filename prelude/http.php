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
    /** @var array<string,string> lowercased name => value, repeats comma-joined */
    private array<string, string> $map = [];

    /** @var array<int,string> wire lines, "Name: value", insertion order */
    private array<int, string> $lines = [];

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
        return isset($this->map[\strtolower($n)]);
    }

    public function get(string $n, string $default = ''): string
    {
        $k = \strtolower($n);
        if (!isset($this->map[$k])) {
            return $default;
        }
        return $this->map[$k];
    }

    /** The value as an int, or $default when absent or not all digits. */
    public function int(string $n, int $default = 0): int
    {
        $k = \strtolower($n);
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
        $k = \strtolower($n);
        $this->dropLines($k);
        $this->map[$k] = $v;
        $this->lines[] = $n . ': ' . $v;
    }

    /**
     * Append a line, keeping any that are already there. The lookup value is
     * comma-joined; the wire keeps both lines, which is what `Set-Cookie` needs.
     */
    public function add(string $n, string $v): void
    {
        $k = \strtolower($n);
        if (isset($this->map[$k])) {
            $this->map[$k] = $this->map[$k] . ', ' . $v;
        } else {
            $this->map[$k] = $v;
        }
        $this->lines[] = $n . ': ' . $v;
    }

    public function remove(string $n): void
    {
        $k = \strtolower($n);
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

    /** @return array<int,string> the wire lines, in order */
    public function lines(): array<int, string>
    {
        return $this->lines;
    }

    /** The wire block, terminating CRLF included. */
    public function render(): string
    {
        return renderLines($this->lines);
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
        $out = [];
        $n = 0;
        foreach ($this->lines as $line) {
            $c = \strpos($line, ':');
            if ($c !== false && \strtolower(\substr($line, 0, $c)) === $lower) {
                continue;
            }
            $out[$n] = $line;
            $n++;
        }
        $this->lines = $out;
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

    /** @var array<string,string> */
    private array<string, string> $queryCache = [];
    /** @var array<string,string> */
    private array<string, string> $cookieCache = [];
    private int $parsed = 0;

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
        /** Present only for a streamed body. */
        private ?\Buffer\Reader $reader = null,
    ) {
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
        $line = \__mc_cookie_line($n, $enc, $expires, $path, $domain, $secure, $httponly, $sameSite);
        // __mc_cookie_line answers the whole wire line, prefix included.
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

    public function __construct(
        \Buffer\ByteBuffer $buf,
        string $remoteAddr = '',
        bool $secure = false,
        int $maxHeaderBytes = 16384,
        int $maxHeaderCount = 100,
        int $maxBodySize = 8388608,
    ) {
        $this->buf = $buf;
        $this->remoteAddr = $remoteAddr;
        $this->secure = $secure;
        $this->maxHeaderBytes = $maxHeaderBytes;
        $this->maxHeaderCount = $maxHeaderCount;
        $this->maxBodySize = $maxBodySize;
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

        $lines = splitHead($head);
        $n = \count($lines);
        if ($n === 0) {
            return 400;
        }
        if ($n - 1 > $this->maxHeaderCount) {
            return 431;
        }
        $rl = reqLine($lines[0]);
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
        for ($i = 1; $i < $n; $i++) {
            $kv = headerSplit($lines[$i]);
            if (\count($kv) !== 2) {
                return 400;
            }
            $h->add($kv[0], $kv[1]);
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
        return $this->framing($h);
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
                return 413;
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
        $h = $this->headers;
        if ($h === null) {
            $h = new Headers();
        }
        $this->req = new Request(
            $this->method,
            $this->target,
            $this->path,
            $this->queryString,
            $this->version,
            $h,
            $this->body,
            false,
            $this->remoteAddr,
            $this->secure,
            null,
        );
        $this->state = self::ST_DONE;
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
    private int $keepAliveMax = 100;
    private string $serverName = 'manticore';
    private bool $secure = false;

    /** How long one `accept` waits before the loop re-reads {@see $stopped}.
     *  This — not closing the listener out from under a parked accept — is what
     *  bounds shutdown latency, and it is the only part of the loop a caller on
     *  another task can affect without racing the reactor. */
    private float $acceptWait = 0.25;

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
    /** `callable(\Throwable, ?Request): Response` */
    public function onError(callable $fn): Server { $this->onError = $fn; return $this; }

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
        \Async\async(function () {
            \Async\shutdownOn(\SIGTERM, \SIGINT);
            $this->loop();
        });
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
        $listener = $this->listener;
        if ($listener === null) {
            if ($this->workerCount > 0) {
                // Fork BEFORE any reactor exists — the only safe order.
                \Process\workers($this->workerCount);
            }
            $errno = 0;
            $errstr = '';
            $l = $this->context === null
                ? \stream_socket_server($this->addr, $errno, $errstr)
                : \stream_socket_server($this->addr, $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $this->context);
            if ($l === false) {
                throw new \RuntimeException('Http\\Server: cannot bind ' . $this->addr . ': ' . $errstr);
            }
            $listener = $l;
            $this->listener = $l;
            \stream_set_blocking($listener, false);
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
        $remote = '';
        $handled = 0;
        while (!$this->stopped) {
            $parser = new Parser(
                $buf,
                $remote,
                $this->secure,
                $this->maxHeaderBytes,
                $this->maxHeaderCount,
                $this->maxBodySize,
            );
            // The FIRST read of a request waits idleTimeout (a kept-alive
            // connection may sit silent for a long time and that is not an
            // error); once bytes have arrived the rest of the head is on
            // headerTimeout, so a client that dribbles a head cannot hold a
            // slot open. A silent client is closed silently, as nginx does.
            $first = $buf->isEmpty();
            \stream_set_timeout($conn, (int)($first ? $this->idleTimeout : $this->headerTimeout));
            $code = $parser->parse();
            $got = !$first;
            while ($code === Parser::NEED) {
                $chunk = \fread($conn, self::READ_CHUNK);
                if ($chunk === '') {
                    if (!$got && $this->timedOut($conn)) {
                        return;                     // idle keep-alive expiry
                    }
                    if (!$got) {
                        return;                     // peer closed between requests
                    }
                    if ($this->timedOut($conn)) {
                        $this->writeError($conn, 408);
                        return;
                    }
                    return;                         // peer closed mid-request
                }
                if (!$got) {
                    $got = true;
                    \stream_set_timeout($conn, (int)$this->headerTimeout);
                }
                $buf->append($chunk);
                $code = $parser->parse();
            }
            if ($code !== Parser::READY) {
                $this->statErrors = $this->statErrors + 1;
                $this->writeError($conn, $code);
                return;
            }
            $req = $parser->request();
            if ($req === null) {
                return;
            }
            $handled = $handled + 1;
            $res = $this->dispatch($req);
            $keep = $req->isKeepAlive()
                && !$res->wantsClose()
                && !$this->stopped
                && $handled < $this->keepAliveMax;
            \stream_set_timeout($conn, (int)$this->writeTimeout);
            $this->writeResponse($conn, $req, $res, $keep);
            $this->statServed = $this->statServed + 1;
            if (!$keep) {
                return;
            }
            // compact(), never clear(): bytes already read past this request are
            // the NEXT one's head, and a pipelining client sends both at once.
            $buf->compact();
        }
    }

    /** Run the handler, turning any escape into a response rather than a crash. */
    private function dispatch(Request $req): Response
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
     * Head + body in ONE `fwrite` — a literal two-element array is a
     * `writev(2)`, so a response costs one syscall rather than two.
     */
    private function writeResponse(\Resource $conn, Request $req, Response $res, bool $keep): void
    {
        $body = $res->getBody();
        $hasBody = Status::hasBody($res->status);
        if (!$hasBody) {
            $body = '';
        }
        $head = $this->renderHead($res, $req->version, $keep, \strlen($body), $hasBody);
        // HEAD carries the headers of the GET it mirrors — Content-Length
        // included — and no body at all.
        if ($req->method === 'HEAD' || $body === '') {
            \fwrite($conn, $head);
            return;
        }
        \fwrite($conn, [$head, $body]);
    }

    private function renderHead(Response $res, string $version, bool $keep, int $len, bool $hasBody): string
    {
        $h = $res->headers;
        if ($hasBody && !$h->has('content-length')) {
            $h->set('Content-Length', (string)$len);
        }
        if (!$h->has('date')) {
            $h->set('Date', httpDate(\time()));
        }
        if ($this->serverName !== '' && !$h->has('server')) {
            $h->set('Server', $this->serverName);
        }
        $h->set('Connection', $keep ? 'keep-alive' : 'close');
        return statusLine($res->status, $version) . $h->render();
    }

    /**
     * A refusal the handler never sees. One string, one write, no allocation
     * beyond the concat — an error path that allocates is an error path a
     * client can turn into a cost.
     */
    private function writeError(\Resource $conn, int $code): void
    {
        $reason = reason($code);
        if ($reason === '') {
            $reason = 'Error';
        }
        \fwrite(
            $conn,
            'HTTP/1.1 ' . $code . ' ' . $reason . "\r\n"
            . "Connection: close\r\nContent-Length: 0\r\n\r\n",
        );
    }

    /** Whether the last short read was a TIMEOUT rather than a close. */
    private function timedOut(\Resource $conn): bool
    {
        $meta = \stream_get_meta_data($conn);
        return isset($meta['timed_out']) && $meta['timed_out'];
    }
}

}
