<?php

/**
 * `require $path` of a file that is not a compile unit — one the program wrote
 * at run time. A compiled binary has no interpreter, but the files a framework
 * writes and reads back are DATA: symfony's router caches its compiled routes
 * as `<?php return [ … ];` (url_matching_routes.php) and `require`s them. This
 * evaluates exactly that shape — one `return` of array / string / number /
 * bool / null literals, `.`-concatenated strings and comments — and answers
 * `false` for an `include` of anything else (a `require` throws `Error: Failed
 * opening required`), since the rest would
 * need code to run.
 */
function __mc_require_runtime(string $path, int $required = 0): mixed
{
    $src = @\file_get_contents($path);
    if (!\is_string($src)) {
        if ($required === 1) { throw new \Error("Failed opening required '" . $path . "' (no such file, and not part of the build)"); }
        return false;
    }
    $n = \strlen($src);
    $ok = false;
    $v = false;
    if (\substr($src, 0, 5) === '<?php') {
        $i = 5;
        \__mc_rd_ws($src, $i, $n);
        if (\strtolower(\substr($src, $i, 6)) === 'return') {
            $i = $i + 6;
            $ok = true;
            $v = \__mc_rd_expr($src, $i, $n, $ok);
            \__mc_rd_ws($src, $i, $n);
            if ($ok && ($i >= $n || $src[$i] !== ';')) { $ok = false; }
        }
    }
    if ($ok) { return $v; }
    if ($required === 1) {
        throw new \Error("Failed opening required '" . $path . "' (not part of the build, and not a pure-data `return` file)");
    }
    return false;
}

/** Skip whitespace and `//`, `#`, `/* … *\/` comments. */
function __mc_rd_ws(string $s, int &$i, int $n): void
{
    while ($i < $n) {
        $c = $s[$i];
        if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") { $i = $i + 1; continue; }
        if ($c === '#' || ($c === '/' && $i + 1 < $n && $s[$i + 1] === '/')) {
            while ($i < $n && $s[$i] !== "\n") { $i = $i + 1; }
            continue;
        }
        if ($c === '/' && $i + 1 < $n && $s[$i + 1] === '*') {
            $e = \strpos($s, '*/', $i + 2);
            $i = $e === false ? $n : $e + 2;
            continue;
        }
        return;
    }
}

/** A term, then any `. term` concatenations. */
function __mc_rd_expr(string $s, int &$i, int $n, bool &$ok): mixed
{
    $v = \__mc_rd_term($s, $i, $n, $ok);
    while ($ok) {
        \__mc_rd_ws($s, $i, $n);
        if ($i >= $n || $s[$i] !== '.') { break; }
        $i = $i + 1;
        $r = \__mc_rd_term($s, $i, $n, $ok);
        if (!$ok || !\is_string($v) || !\is_string($r)) { $ok = false; return null; }
        $v = $v . $r;
    }
    return $v;
}

function __mc_rd_term(string $s, int &$i, int $n, bool &$ok): mixed
{
    \__mc_rd_ws($s, $i, $n);
    if ($i >= $n) { $ok = false; return null; }
    $c = $s[$i];
    if ($c === '[') {
        $i = $i + 1;
        return \__mc_rd_items($s, $i, $n, ']', $ok);
    }
    if ($c === "'") { return \__mc_rd_single($s, $i, $n, $ok); }
    if ($c === '"') { return \__mc_rd_double($s, $i, $n, $ok); }
    if ($c === '-' || $c === '+' || ($c >= '0' && $c <= '9')) { return \__mc_rd_number($s, $i, $n, $ok); }
    $j = $i;
    while ($j < $n && (\ctype_alpha($s[$j]) || $s[$j] === '_')) { $j = $j + 1; }
    $word = \strtolower(\substr($s, $i, $j - $i));
    if ($word === 'array') {
        $i = $j;
        \__mc_rd_ws($s, $i, $n);
        if ($i >= $n || $s[$i] !== '(') { $ok = false; return null; }
        $i = $i + 1;
        return \__mc_rd_items($s, $i, $n, ')', $ok);
    }
    $i = $j;
    if ($word === 'true') { return true; }
    if ($word === 'false') { return false; }
    if ($word === 'null') { return null; }
    $ok = false;
    return null;
}

/** @return array<array-key, mixed> */
function __mc_rd_items(string $s, int &$i, int $n, string $close, bool &$ok): array
{
    $out = [];
    while (true) {
        \__mc_rd_ws($s, $i, $n);
        if ($i >= $n) { $ok = false; return []; }
        if ($s[$i] === $close) { $i = $i + 1; return $out; }
        $k = \__mc_rd_expr($s, $i, $n, $ok);
        if (!$ok) { return []; }
        \__mc_rd_ws($s, $i, $n);
        if ($i + 1 < $n && $s[$i] === '=' && $s[$i + 1] === '>') {
            $i = $i + 2;
            $v = \__mc_rd_expr($s, $i, $n, $ok);
            if (!$ok) { return []; }
            if (\is_int($k) || \is_string($k)) { $out[$k] = $v; }
            elseif (\is_bool($k) || $k === null) { $out[(int)$k] = $v; }
            else { $ok = false; return []; }
        } else {
            $out[] = $k;
        }
        \__mc_rd_ws($s, $i, $n);
        if ($i < $n && $s[$i] === ',') { $i = $i + 1; continue; }
        if ($i < $n && $s[$i] === $close) { $i = $i + 1; return $out; }
        $ok = false;
        return [];
    }
}

function __mc_rd_single(string $s, int &$i, int $n, bool &$ok): string
{
    $i = $i + 1;
    $out = '';
    while ($i < $n) {
        $c = $s[$i];
        if ($c === "'") { $i = $i + 1; return $out; }
        if ($c === '\\' && $i + 1 < $n && ($s[$i + 1] === "'" || $s[$i + 1] === '\\')) {
            $out .= $s[$i + 1];
            $i = $i + 2;
            continue;
        }
        $out .= $c;
        $i = $i + 1;
    }
    $ok = false;
    return '';
}

/** A double-quoted string WITHOUT interpolation (a `$` there would need code). */
function __mc_rd_double(string $s, int &$i, int $n, bool &$ok): string
{
    $i = $i + 1;
    $out = '';
    while ($i < $n) {
        $c = $s[$i];
        if ($c === '"') { $i = $i + 1; return $out; }
        if ($c === '$') { $ok = false; return ''; }
        if ($c === '\\' && $i + 1 < $n) {
            $e = $s[$i + 1];
            $i = $i + 2;
            if ($e === 'n') { $out .= "\n"; }
            elseif ($e === 't') { $out .= "\t"; }
            elseif ($e === 'r') { $out .= "\r"; }
            elseif ($e === 'v') { $out .= "\v"; }
            elseif ($e === 'f') { $out .= "\f"; }
            elseif ($e === 'e') { $out .= "\e"; }
            elseif ($e === '0' && !($i < $n && $s[$i] >= '0' && $s[$i] <= '7')) { $out .= "\0"; }
            elseif ($e === '\\' || $e === '"' || $e === '$') { $out .= $e; }
            elseif ($e === 'x' && $i < $n && \ctype_xdigit($s[$i])) {
                $h = $s[$i];
                $i = $i + 1;
                if ($i < $n && \ctype_xdigit($s[$i])) { $h .= $s[$i]; $i = $i + 1; }
                $out .= \chr(\hexdec($h));
            } elseif ($e >= '0' && $e <= '7') {
                $o = $e;
                while (\strlen($o) < 3 && $i < $n && $s[$i] >= '0' && $s[$i] <= '7') { $o .= $s[$i]; $i = $i + 1; }
                $out .= \chr(\octdec($o) & 255);
            } else { $out .= '\\' . $e; }
            continue;
        }
        $out .= $c;
        $i = $i + 1;
    }
    $ok = false;
    return '';
}

function __mc_rd_number(string $s, int &$i, int $n, bool &$ok): int|float
{
    $neg = false;
    if ($s[$i] === '-' || $s[$i] === '+') {
        $neg = $s[$i] === '-';
        $i = $i + 1;
        \__mc_rd_ws($s, $i, $n);
    }
    $j = $i;
    while ($j < $n && (\ctype_alnum($s[$j]) || $s[$j] === '.' || $s[$j] === '_'
        || (($s[$j] === '-' || $s[$j] === '+') && ($s[$j - 1] === 'e' || $s[$j - 1] === 'E')))) { $j = $j + 1; }
    $t = \str_replace('_', '', \substr($s, $i, $j - $i));
    $i = $j;
    if ($t === '') { $ok = false; return 0; }
    $lt = \strtolower($t);
    if (\str_starts_with($lt, '0x')) { $v = \hexdec(\substr($t, 2)); }
    elseif (\str_starts_with($lt, '0b')) { $v = \bindec(\substr($t, 2)); }
    elseif (\str_contains($lt, '.') || \str_contains($lt, 'e') || $lt === 'inf' || $lt === 'nan') { $v = (float)$t; }
    elseif (\strlen($t) > 1 && $t[0] === '0') { $v = \octdec(\ltrim(\substr($lt, 1), 'o')); }
    elseif (\ctype_digit($t)) { $v = (int)$t; }
    else { $ok = false; return 0; }
    return $neg ? -$v : $v;
}

/**
 * `eval()` has no interpreter behind it: the code is NOT run and the result is `null`. A
 * language construct every php has, so the call resolves instead of trapping; code that
 * needs the evaluated declarations (Twig's `eval('?>'.$content)` of a freshly compiled
 * template) must have them in the build ahead of time and fails on its own, loudly, when
 * they are missing.
 */
function eval(string $code): mixed
{
    return null;
}
