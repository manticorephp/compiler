<?php

// ext/filter: filter_var / filter_var_array / filter_input / filter_input_array /
// filter_has_var / filter_list / filter_id. Port of php-src ext/filter
// (logical_filters.c, sanitizing_filters.c, filter.c). Where php warns
// (unknown filter id, invalid callback) it throws, per Manticore's
// no-warnings rule. INPUT_* sources are the runtime's $_GET / $_POST /
// $_COOKIE / $_SERVER superglobals; INPUT_ENV is the process environment.

/** @return string[] */
function __mc_filter_names(): array
{
    return ['int', 'boolean', 'float', 'validate_regexp', 'validate_domain', 'validate_url',
        'validate_email', 'validate_ip', 'validate_mac', 'string', 'stripped', 'encoded',
        'special_chars', 'full_special_chars', 'unsafe_raw', 'email', 'url', 'number_int',
        'number_float', 'add_slashes', 'callback'];
}

/** @return int[] */
function __mc_filter_ids(): array
{
    return [257, 258, 259, 272, 277, 273, 274, 275, 276, 513, 513, 514, 515, 522, 516, 517, 518, 519, 520, 523, 1024];
}

function filter_list(): array
{
    return __mc_filter_names();
}

function filter_id(string $name): int|false
{
    $names = __mc_filter_names();
    $ids = __mc_filter_ids();
    foreach ($names as $i => $n) {
        if ($n === $name) { return $ids[$i]; }
    }
    return false;
}

function __mc_filter_name_of(int $id): string
{
    $names = __mc_filter_names();
    $ids = __mc_filter_ids();
    foreach ($ids as $i => $v) {
        if ($v === $id) { return $names[$i]; }
    }
    return '';
}

function __mc_filter_known(int $id): bool
{
    return __mc_filter_name_of($id) !== '';
}

function __mc_filter_throw(string $msg): never
{
    // the class lives in prelude/filter.php (demand-gated), unknown to the stdlib build: name it dynamically
    $cls = 'Filter\\FilterFailedException';
    throw new $cls('filter validation failed: ' . $msg);
}

/** php_filter_trim: leading/trailing whitespace of the validators. */
function __mc_filter_trim(string $s): string
{
    return \trim($s, " \t\n\r\v");
}

function __mc_filter_opt_int(array $o, string $k): ?int
{
    if (!\array_key_exists($k, $o)) { return null; }
    return (int)$o[$k];
}

/** 0 = fail, else the parsed int (null = fail) */
function __mc_filter_int(string $v, int $flags, array $o): ?int
{
    $s = __mc_filter_trim($v);
    $n = \strlen($s);
    if ($n === 0) { return null; }
    $r = null;
    if ($s[0] === '0' && $n > 1) {
        $c = $s[1];
        if (($c === 'x' || $c === 'X') && ($flags & 2) !== 0) {
            $h = \substr($s, 2);
            if ($h === '' || !\ctype_xdigit($h)) { return null; }
            $r = 0;
            $hl = \strlen($h);
            for ($i = 0; $i < $hl; $i++) {
                if ((($r >> 60) & 15) !== 0) { return null; }
                $r = ($r << 4) | \hexdec($h[$i]);
            }
        } elseif (($flags & 1) !== 0) {
            $d = \substr($s, 1);
            if ($d !== '' && ($d[0] === 'o' || $d[0] === 'O')) { $d = \substr($d, 1); }
            if ($d === '' || \preg_match('/^[0-7]+$/', $d) !== 1) { return null; }
            $r = 0;
            $dl = \strlen($d);
            for ($i = 0; $i < $dl; $i++) {
                if ((($r >> 61) & 7) !== 0) { return null; }
                $r = ($r << 3) | (\ord($d[$i]) - 48);
            }
        } else {
            return null;
        }
    } else {
        // `[+-]?(0|[1-9][0-9]*)` by hand: a regex per integer cost more than the parse.
        $sg = $s[0];
        $dg = $s;
        if ($sg === '+' || $sg === '-') { $dg = \substr($s, 1); } else { $sg = ''; }
        if ($dg === '' || !\ctype_digit($dg) || ($dg[0] === '0' && \strlen($dg) > 1)) { return null; }
        if ($dg === '0') {
            $r = 0;
        } else {
            $neg = $sg === '-';
            $lim = $neg ? '9223372036854775808' : '9223372036854775807';
            if (\strlen($dg) > 19 || (\strlen($dg) === 19 && \strcmp($dg, $lim) > 0)) { return null; }
            $r = $neg ? (\strlen($dg) === 19 && $dg === $lim ? \PHP_INT_MIN : -(int)$dg) : (int)$dg;
        }
    }
    $mn = __mc_filter_opt_int($o, 'min_range');
    $mx = __mc_filter_opt_int($o, 'max_range');
    if ($mn !== null && $r < $mn) { return null; }
    if ($mx !== null && $r > $mx) { return null; }
    return $r;
}

function __mc_filter_float(string $fn, string $v, int $flags, array $o): ?float
{
    $dec = '.';
    if (\array_key_exists('decimal', $o)) {
        $dec = (string)$o['decimal'];
        if (\strlen($dec) !== 1) { throw new \ValueError($fn . '(): "decimal" option must be one character long'); }
    }
    $tsd = "',.";
    if (\array_key_exists('thousand', $o)) {
        $tsd = (string)$o['thousand'];
        if ($tsd === '') { throw new \ValueError($fn . '(): "thousand" option must not be empty'); }
    }
    $s = __mc_filter_trim($v);
    $end = \strlen($s);
    if ($end === 0) { return null; }
    $i = 0;
    $out = '';
    if ($s[$i] === '-' || $s[$i] === '+') { $out .= $s[$i]; $i++; }
    $first = true;
    while (true) {
        $n = 0;
        while ($i < $end && $s[$i] >= '0' && $s[$i] <= '9') { $n++; $out .= $s[$i]; $i++; }
        $c = $i < $end ? $s[$i] : '';
        if ($i === $end || $c === $dec || $c === 'e' || $c === 'E') {
            if (!$first && $n !== 3) { return null; }
            if ($c === $dec) {
                $out .= '.';
                $i++;
                while ($i < $end && $s[$i] >= '0' && $s[$i] <= '9') { $out .= $s[$i]; $i++; }
            }
            $c = $i < $end ? $s[$i] : '';
            if ($c === 'e' || $c === 'E') {
                $out .= $c;
                $i++;
                if ($i < $end && ($s[$i] === '+' || $s[$i] === '-')) { $out .= $s[$i]; $i++; }
                while ($i < $end && $s[$i] >= '0' && $s[$i] <= '9') { $out .= $s[$i]; $i++; }
            }
            break;
        }
        if (($flags & 8192) !== 0 && \strpos(\substr($tsd, 0, 3), $c) !== false) {
            if ($first ? ($n < 1 || $n > 3) : ($n !== 3)) { return null; }
            $first = false;
            $i++;
        } else {
            return null;
        }
    }
    if ($i !== $end) { return null; }
    if (!\is_numeric($out)) { return null; }
    $f = \preg_match('/^[+-]?[0-9]+$/', $out) === 1 && \strlen($out) < 19 ? (float)(int)$out : (float)$out;
    if (($f == 0.0 && \strlen($out) > 1 && \preg_match('/[1-9]/', $out) === 1) || \is_infinite($f) || \is_nan($f)) { return null; }
    $mn = \array_key_exists('min_range', $o) ? (float)$o['min_range'] : null;
    $mx = \array_key_exists('max_range', $o) ? (float)$o['max_range'] : null;
    if ($mn !== null && $f < $mn) { return null; }
    if ($mx !== null && $f > $mx) { return null; }
    return $f;
}

function __mc_filter_bool(string $v): ?bool
{
    $s = \strtolower(__mc_filter_trim($v));
    if ($s === '1' || $s === 'true' || $s === 'on' || $s === 'yes') { return true; }
    if ($s === '0' || $s === 'false' || $s === 'off' || $s === 'no' || $s === '') { return false; }
    return null;
}

function __mc_filter_alnum(string $c): bool
{
    return ($c >= '0' && $c <= '9') || ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z');
}

function __mc_filter_domain_ok(string $s, bool $hostname): bool
{
    $l = \strlen($s);
    if ($l > 0 && $s[$l - 1] === '.') { $l--; }
    if ($l > 253) { return false; }
    if ($l === 0) { return !$hostname; }
    if ($s[0] === '.' || ($hostname && !__mc_filter_alnum($s[0]))) { return false; }
    $i = 1;
    for ($p = 0; $p < $l - 1 + 0; $p++) {
        // php walks s..e where e is the LAST char (exclusive of it)
        $ch = $s[$p];
        if ($ch === '.') {
            $nx = $s[$p + 1];
            if ($nx === '.' || ($hostname && (!__mc_filter_alnum($s[$p - 1]) || !__mc_filter_alnum($nx)))) { return false; }
            $i = 1;
        } else {
            if ($i > 63 || ($hostname && $ch !== '-' && !__mc_filter_alnum($ch))) { return false; }
            $i++;
        }
    }
    if ($hostname && !__mc_filter_alnum($s[$l - 1])) { return false; }
    return true;
}

function __mc_filter_ipv4_parts(string $s): ?array
{
    if (\preg_match('/^(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})$/', $s, $m) !== 1) { return null; }
    $r = [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4]];
    foreach ($r as $x) { if ($x > 255) { return null; } }
    return $r;
}

function __mc_filter_ipv6_ok(string $s): bool
{
    $n = \strlen($s);
    if ($n < 2) { return false; }
    $dbl = \substr_count($s, '::');
    if ($dbl > 1 || \strpos($s, ':::') !== false) { return false; }
    $groups = 0;
    $tail = $s;
    $lastColon = \strrpos($s, ':');
    $last = \substr($s, $lastColon + 1);
    if (\strpos($last, '.') !== false) {
        if (__mc_filter_ipv4_parts($last) === null) { return false; }
        $groups = 2;
        $tail = \substr($s, 0, $lastColon + 1);
        if ($tail === '' ) { return false; }
        if (\substr($tail, -2) !== '::') { $tail = \substr($tail, 0, -1); }
    }
    if ($tail === '::') { return $dbl === 1; }
    $parts = \explode(':', $tail);
    $np = \count($parts);
    $comp = $dbl === 1;
    for ($i = 0; $i < $np; $i++) {
        $p = $parts[$i];
        if ($p === '') {
            if (!$comp) { return false; }
            // empty slots are only legal around the single '::'
            $okEdge = ($i === 0 && $np > 1 && $parts[1] === '') || ($i === $np - 1 && $np > 1 && $parts[$np - 2] === '')
                || ($i > 0 && $i < $np - 1);
            if (!$okEdge) { return false; }
            continue;
        }
        if (\strlen($p) > 4 || !\ctype_xdigit($p)) { return false; }
        $groups++;
    }
    if ($comp) { return $groups <= 7; }
    return $groups === 8;
}

function __mc_filter_ip(string $v, int $flags): ?string
{
    $v4 = ($flags & 1048576) !== 0;
    $v6 = ($flags & 2097152) !== 0;
    if (!$v4 && !$v6) { $v4 = true; $v6 = true; }
    $priv = ($flags & 8388608) !== 0 || ($flags & 536870912) !== 0;
    $res = ($flags & 4194304) !== 0 || ($flags & 536870912) !== 0;
    $glob = ($flags & 536870912) !== 0;
    if (\strpos($v, ':') !== false) {
        if (!$v6 || !__mc_filter_ipv6_ok($v)) { return null; }
        $b = \inet_pton($v);
        if (!\is_string($b) || \strlen($b) !== 16) { return null; }
        if (($res || $glob) && \strpos($v, '.') !== false) { return null; }   // php: an embedded-IPv4 tail is always rejected here
        $ip = \array_values(\unpack('C16', $b));
        $w0 = $ip[0] * 256 + $ip[1];
        $w1 = $ip[2] * 256 + $ip[3];
        $zero = true;
        for ($i = 0; $i < 15; $i++) { if ($ip[$i] !== 0) { $zero = false; } }
        if ($priv && ($ip[0] & 0xfe) === 0xfc) { return null; }
        if ($res) {
            if ($zero && ($ip[15] === 0 || $ip[15] === 1)) { return null; }
            if ($w0 === 0x0000 && $ip[2] === 0 && $ip[3] === 0 && $ip[4] === 0 && $ip[5] === 0 && $ip[6] === 0 && $ip[7] === 0
                && $ip[8] === 0 && $ip[9] === 0 && $ip[10] === 0xff && $ip[11] === 0xff) { return null; }
            if ($ip[0] === 0xfe && ($ip[1] & 0xc0) === 0x80) { return null; }
        }
        if ($glob) {
            if (($w0 === 0x2001 && ($w1 < 0x200 || $w1 === 0x0db8)) || $w0 === 0x2002) { return null; }
            if ($w0 === 0x0100 && $w1 === 0) { return null; }
        }
        return $v;
    }
    if (!$v4) { return null; }
    $ip = __mc_filter_ipv4_parts($v);
    if ($ip === null) { return null; }
    if ($priv) {
        if ($ip[0] === 10 || ($ip[0] === 172 && $ip[1] >= 16 && $ip[1] <= 31) || ($ip[0] === 192 && $ip[1] === 168)) { return null; }
    }
    if ($res) {
        if ($ip[0] === 0 || $ip[0] >= 240 || $ip[0] === 127 || ($ip[0] === 169 && $ip[1] === 254)) { return null; }
    }
    if ($glob) {
        if (($ip[0] === 100 && ($ip[1] & 0xc0) === 0x40)
            || ($ip[0] === 192 && $ip[1] === 0 && $ip[2] === 0 && $ip[3] !== 9 && $ip[3] !== 10)
            || ($ip[0] === 192 && $ip[1] === 0 && $ip[2] === 2)
            || ($ip[0] === 198 && ($ip[1] & 0xfe) === 18)
            || ($ip[0] === 198 && $ip[1] === 51 && $ip[2] === 100)
            || ($ip[0] === 203 && $ip[1] === 0 && $ip[2] === 113)) { return null; }
    }
    return $v;
}

function __mc_filter_hex_ok(string $s): bool
{
    return $s !== '' && \ctype_xdigit($s);
}

function __mc_filter_mac(string $fn, string $v, array $o): ?string
{
    $exp = '';
    if (\array_key_exists('separator', $o)) {
        $exp = (string)$o['separator'];
        if (\strlen($exp) !== 1) { throw new \ValueError($fn . '(): "separator" option must be one character long'); }
    }
    $n = \strlen($v);
    if ($n === 14) { $tokens = 3; $len = 4; $sep = '.'; }
    elseif ($n === 17 && $v[2] === '-') { $tokens = 6; $len = 2; $sep = '-'; }
    elseif ($n === 17 && $v[2] === ':') { $tokens = 6; $len = 2; $sep = ':'; }
    else { return null; }
    if ($exp !== '' && $sep !== $exp) { return null; }
    for ($i = 0; $i < $tokens; $i++) {
        $off = $i * ($len + 1);
        if ($i < $tokens - 1 && $v[$off + $len] !== $sep) { return null; }
        if (!__mc_filter_hex_ok(\substr($v, $off, $len))) { return null; }
    }
    return $v;
}

function __mc_filter_userinfo_ok(string $s): bool
{
    return \preg_match('/^(?:[A-Za-z0-9\-._~!$&\'()*+,;=:]|%[0-9A-Fa-f]{2})*$/', $s) === 1;
}

function __mc_filter_url(string $v, int $flags): ?string
{
    if (\preg_match('/^[A-Za-z0-9$\-_.+!*\'(),{}|\\\\^~\[\]`<>#%";\/?:@&=]*$/', $v) !== 1) { return null; }
    $u = \parse_url($v);
    if (!\is_array($u)) { return null; }
    $scheme = isset($u['scheme']) ? (string)$u['scheme'] : null;
    $host = isset($u['host']) ? (string)$u['host'] : null;
    if ($scheme !== null && (\strcasecmp($scheme, 'http') === 0 || \strcasecmp($scheme, 'https') === 0)) {
        if ($host === null) { return null; }
        $l = \strlen($host);
        $isV6 = $host[0] === '[' && $host[$l - 1] === ']' && __mc_filter_ipv6_ok(\substr($host, 1, $l - 2));
        if (!$isV6 && !__mc_filter_domain_ok($host, true)) { return null; }
        if ($isV6) {
            $host = '[]';
        }
    }
    if ($scheme === null) { return null; }
    if ($host === null && $scheme !== 'mailto' && $scheme !== 'news' && $scheme !== 'file') { return null; }
    if (($flags & 262144) !== 0 && !isset($u['path'])) { return null; }
    if (($flags & 524288) !== 0 && !isset($u['query'])) { return null; }
    if (isset($u['user']) && !__mc_filter_userinfo_ok((string)$u['user'])) { return null; }
    if (isset($u['pass']) && !__mc_filter_userinfo_ok((string)$u['pass'])) { return null; }
    return $v;
}

function __mc_filter_email(string $v, int $flags): ?string
{
    if (\strlen($v) > 320) { return null; }
    if (($flags & 1048576) !== 0) {
        $re = '/^(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E\pL\pN]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F\pL\pN]|(?:\x5C[\x00-\x7F]))*\x22))(?:\.(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E\pL\pN]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F\pL\pN]|(?:\x5C[\x00-\x7F]))*\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\]))$/iDu';
    } else {
        $re = '/^(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22))(?:\.(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\]))$/iD';
    }
    return \preg_match($re, $v) === 1 ? $v : null;
}

function __mc_filter_strip(string $s, int $flags): string
{
    if (($flags & 4) !== 0) { $s = (string)\preg_replace('/[\x00-\x1f]/', '', $s); }
    if (($flags & 8) !== 0) { $s = (string)\preg_replace('/[\x7f-\xff]/', '', $s); }
    if (($flags & 512) !== 0) { $s = \str_replace('`', '', $s); }
    return $s;
}

/** php_filter_encode_html: `&#N;` for each flagged byte. $enc is a set of byte classes. */
function __mc_filter_encode_html(string $s, string $set, bool $low, bool $high): string
{
    $n = \strlen($s);
    $out = '';
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        $o = \ord($c);
        if (($set !== '' && \strpos($set, $c) !== false) || ($low && $o < 32) || ($high && $o >= 127)) {
            $out .= '&#' . $o . ';';
        } else {
            $out .= $c;
        }
    }
    return $out;
}

function __mc_filter_sanitize(int $filter, string $s, int $flags): mixed
{
    if ($filter === 516) {
        if ($s === '') { return ($flags & 256) !== 0 ? null : ''; }
        if ($flags !== 0) {
            $s = __mc_filter_strip($s, $flags);
            $set = ($flags & 64) !== 0 ? '&' : '';
            $s = __mc_filter_encode_html($s, $set, ($flags & 16) !== 0, ($flags & 32) !== 0);
        }
        return $s;
    }
    if ($filter === 513) {
        $s = __mc_filter_strip($s, $flags);
        $set = (($flags & 128) === 0 ? "'\"" : '') . (($flags & 64) !== 0 ? '&' : '');
        $s = __mc_filter_encode_html($s, $set, ($flags & 16) !== 0, ($flags & 32) !== 0);
        $s = \strip_tags($s);
        if ($s === '') { return ($flags & 256) !== 0 ? null : ''; }
        return $s;
    }
    if ($filter === 514) {
        $s = __mc_filter_strip($s, $flags);
        $out = '';
        $n = \strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if (__mc_filter_alnum($c) || $c === '-' || $c === '.' || $c === '_') {
                $out .= $c;
            } else {
                $out .= '%' . \strtoupper(\bin2hex($c));
            }
        }
        return $out;
    }
    if ($filter === 515) {
        $s = __mc_filter_strip($s, $flags);
        return __mc_filter_encode_html($s, "'\"<>&", true, ($flags & 32) !== 0);
    }
    if ($filter === 522) {
        $q = ($flags & 128) !== 0 ? \ENT_NOQUOTES : \ENT_QUOTES;
        return \htmlentities($s, $q, 'UTF-8', false);
    }
    if ($filter === 517) {
        return (string)\preg_replace('/[^A-Za-z0-9!#$%&\'*+\-=?^_`{|}~@.\[\]]/', '', $s);
    }
    if ($filter === 518) {
        return (string)\preg_replace('/[^A-Za-z0-9$\-_.+!*\'(),{}|\\\\^~\[\]`<>#%";\/?:@&=]/', '', $s);
    }
    if ($filter === 519) {
        return (string)\preg_replace('/[^0-9+\-]/', '', $s);
    }
    if ($filter === 520) {
        $keep = '0-9+\-';
        if (($flags & 4096) !== 0) { $keep .= '.'; }
        if (($flags & 8192) !== 0) { $keep .= ','; }
        if (($flags & 16384) !== 0) { $keep .= 'eE'; }
        return (string)\preg_replace('/[^' . $keep . ']/', '', $s);
    }
    return \addslashes($s);   // 523
}

/** @return array<string,mixed> */
function __mc_filter_opts(mixed $o): array
{
    return \is_array($o) ? $o : [];
}

/**
 * Failure of one scalar: THROW -> FilterFailedException; else false / null,
 * then the `default` option.
 */
function __mc_filter_failed(int $flags, mixed $opts, string $why, bool $useDefault = true): mixed
{
    if (($flags & 268435456) !== 0) { __mc_filter_throw($why); }
    if ($useDefault && \is_array($opts) && \array_key_exists('default', $opts)) { return $opts['default']; }
    return ($flags & 134217728) !== 0 ? null : false;
}

function __mc_filter_show(string $s): string
{
    $z = \strpos($s, "\0");
    return "'" . ($z === false ? $s : \substr($s, 0, $z)) . "'";
}

/** php_zval_filter on one non-array value. */
function __mc_filter_scalar(string $fn, mixed $value, int $filter, int $flags, mixed $opts): mixed
{
    if (\is_object($value)) {
        if (!($value instanceof \Stringable)) {
            return __mc_filter_failed($flags, $opts, 'object of type ' . \get_class($value) . ' has no __toString() method');
        }
        $value = (string)$value;
    }
    if (!\is_string($value)) {
        if (\is_resource($value)) { return __mc_filter_failed($flags, $opts, 'not a scalar value (got a resource)'); }
        $value = (string)$value;
    }
    $s = (string)$value;
    $name = __mc_filter_name_of($filter);
    $o = __mc_filter_opts($opts);
    $fail = null;
    $r = null;
    if ($filter === 257) { $r = __mc_filter_int($s, $flags, $o); }
    elseif ($filter === 258) { $r = __mc_filter_bool($s); }
    elseif ($filter === 259) { $r = __mc_filter_float($fn, $s, $flags, $o); }
    elseif ($filter === 272) {
        if (!\array_key_exists('regexp', $o)) { throw new \ValueError($fn . '(): "regexp" option is missing'); }
        $r = \preg_match((string)$o['regexp'], $s) === 1 ? $s : null;
    }
    elseif ($filter === 277) { $r = __mc_filter_domain_ok($s, ($flags & 1048576) !== 0) ? $s : null; }
    elseif ($filter === 273) { $r = __mc_filter_url($s, $flags); }
    elseif ($filter === 274) { $r = __mc_filter_email($s, $flags); }
    elseif ($filter === 275) { $r = __mc_filter_ip($s, $flags); }
    elseif ($filter === 276) { $r = __mc_filter_mac($fn, $s, $o); }
    else { return __mc_filter_sanitize($filter, $s, $flags); }
    if ($r === null) {
        return __mc_filter_failed($flags, $opts, 'filter ' . $name . ' not satisfied by ' . __mc_filter_show($s));
    }
    return $r;
}
