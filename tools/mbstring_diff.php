<?php

/**
 * Differential fuzz of src/Runtime/Stdlib/Mbstring.php against Zend's mbstring.
 *
 * The stdlib file is loaded under Zend with every `mb_*` it defines renamed to
 * `__z_mb_*`, so both implementations run side by side in one process over the
 * same random inputs — strings built from valid, truncated, overlong, surrogate
 * and stray-continuation UTF-8 pieces, every substitute mode, four encodings.
 *
 *   php -d xdebug.mode=off tools/mbstring_diff.php [seed] [iterations]
 *
 * Skipped on purpose: a positive search offset whose mblen walk runs past a
 * truncated trailing sequence, and a NUL needle in a malformed haystack — there
 * Zend reads past the end of the string (its terminator, then the heap), and the
 * answer is not a contract.
 */

if (!\extension_loaded('mbstring')) {
    \fwrite(STDERR, "needs php with ext/mbstring\n");
    exit(2);
}
$src = \file_get_contents(__DIR__ . '/../src/Runtime/Stdlib/Mbstring.php');
$src = \str_replace(['function mb_', '\\mb_'], ['function __z_mb_', '\\__z_mb_'], $src);
$tmp = \tempnam(\sys_get_temp_dir(), 'mbdiff');
\file_put_contents($tmp, $src);
require $tmp;
\unlink($tmp);

\mt_srand((int)($argv[1] ?? 1));
$iterations = (int)($argv[2] ?? 20000);
$alpha = ["a", "b", "x", " ", "\x80", "\xBF", "\xC3", "\xC3\xA9", "\xE4\xB8\xAD", "\xE4\xB8", "\xED\xA0\x80",
    "\xF0\x9F\x98\x80", "\xF0\x9F", "\xF4\x90\x80\x80", "\xC0\xAF", "\xFF", "\xE2\x80\x83", "\x00", "\xE0\x80"];

function rs(array $alpha, int $max): string
{
    $s = "";
    $n = \mt_rand(0, $max);
    for ($i = 0; $i < $n; $i++) { $s .= $alpha[\mt_rand(0, \count($alpha) - 1)]; }
    return $s;
}

function run(callable $f): string
{
    try {
        return \var_export($f(), true);
    } catch (\Throwable $e) {
        return \get_class($e) . ": " . $e->getMessage();
    }
}

$encs = ["UTF-8", "UTF-8", "UTF-8", "ISO-8859-1", "ASCII", "8bit"];
$subs = [63, "none", 0x263A, "long"];
$bad = 0;
for ($it = 0; $it < $iterations && $bad < 15; $it++) {
    $sub = $subs[\mt_rand(0, 3)];
    \mb_substitute_character($sub);
    \__z_mb_substitute_character($sub);
    $e = $encs[\mt_rand(0, 5)];
    $h = rs($alpha, 8);
    $n = rs($alpha, 2);
    $o = \mt_rand(-6, 6);
    $l = \mt_rand(0, 1) ? null : \mt_rand(-6, 6);
    $pt = [STR_PAD_LEFT, STR_PAD_RIGHT, STR_PAD_BOTH][\mt_rand(0, 2)];
    $tests = [
        'strlen' => fn($p) => $p('strlen')($h, $e),
        'substr' => fn($p) => $p('substr')($h, $o, $l, $e),
        'strcut' => fn($p) => $p('strcut')($h, $o, $l, $e),
        'str_split' => fn($p) => $p('str_split')($h, \mt_rand(1, 3), $e),
        'strpos' => fn($p) => $p('strpos')($h, $n, $o, $e),
        'strrpos' => fn($p) => $p('strrpos')($h, $n, $o, $e),
        'strstr' => fn($p) => $p('strstr')($h, $n, (bool)($o & 1), $e),
        'strrchr' => fn($p) => $p('strrchr')($h, $n, (bool)($o & 1), $e),
        'substr_count' => fn($p) => $p('substr_count')($h, $n, $e),
        'check_encoding' => fn($p) => $p('check_encoding')($h, $e),
        'scrub' => fn($p) => $p('scrub')($h, $e),
        'ord' => fn($p) => $p('ord')($h, $e),
        'chr' => fn($p) => $p('chr')([0x41, 0xE9, 0x4E2D, 0x1F600, 0xD800, 0x110000, -1, 0xFF, 0x80][\mt_rand(0, 8)], $e),
        'str_pad' => fn($p) => $p('str_pad')($h, \mt_rand(0, 12), $n === "" ? "-" : $n, $pt, $e),
        'trim' => fn($p) => $p('trim')($h, $o > 3 ? $n : null, $e),
        'ltrim' => fn($p) => $p('ltrim')($h, $o > 3 ? $n : null, $e),
        'rtrim' => fn($p) => $p('rtrim')($h, $o > 3 ? $n : null, $e),
    ];
    $overrun = false;
    if ($e === "UTF-8" && $o > 0) {
        $p = 0;
        for ($k = $o; $k > 0 && $p < \strlen($h); $k--) { $p += \__mc_mb_u8_mblen(\ord($h[$p])); }
        $overrun = $p > \strlen($h);
    }
    $overrun = $overrun || (\str_contains($n, "\0") && !\mb_check_encoding($h, "UTF-8"));
    $seed = \mt_rand();
    foreach ($tests as $name => $t) {
        if ($overrun && \in_array($name, ["strpos", "strrpos", "strstr", "strrchr"], true)) { continue; }
        \mt_srand($seed);
        $zend = run(fn() => $t(fn($f) => "mb_$f"));
        \mt_srand($seed);
        $ours = run(fn() => $t(fn($f) => "__z_mb_$f"));
        if ($zend !== $ours) {
            $bad++;
            echo "mb_$name enc=$e sub=", \var_export($sub, true), " h=", \bin2hex($h), " n=", \bin2hex($n),
                " o=$o l=", \var_export($l, true), "\n  zend: ", \substr($zend, 0, 120), "\n  ours: ", \substr($ours, 0, 120), "\n";
        }
    }
}
echo "mismatches: $bad\n";
exit($bad === 0 ? 0 : 1);
