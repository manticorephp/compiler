<?php

/**
 * Compare every global function the prelude and the stdlib declare against
 * php's OWN signature for the same name, and report the disagreements in
 * arity and by-reference-ness.
 *
 * Zend is the oracle, and `ReflectionFunction` is Zend answering directly —
 * no hand-maintained table of expected signatures to drift.
 *
 * Why by-ref-ness specifically: an argument in a by-reference position is a
 * DEFINITION. `headers_sent($file, $line)` against a zero-parameter
 * declaration left $file with no definition anywhere, and the front end
 * refused the whole program (`MIR.verify: dangling local $file read`) —
 * symfony/http-foundation's NativeSessionStorage::start(), the tier-3
 * blocker. A missing `&` is not a subtle wrong answer; it can be a build
 * failure in a caller we have never seen.
 *
 *   php tools/audit/refmask_scan.php [--tsv <path>]
 *
 * Exit 0 always: this reports, it does not gate. Params we declare BEYOND
 * php's own are ignored (an extension point, not a divergence).
 */

$root = \dirname(__DIR__, 2);
$out = '';
$argvv = $argv;
for ($i = 1; $i < \count($argvv); $i++) {
    if ($argvv[$i] === '--tsv' && isset($argvv[$i + 1])) { $out = $argvv[$i + 1]; $i++; }
}

$roots = [$root . '/prelude', $root . '/src/Runtime/Stdlib'];

/** @var array<string, array<int, array{file:string, params:array<int, array{0:string,1:bool}>}>> */
$decls = [];
foreach ($roots as $dir) {
    foreach (\glob($dir . '/*.php') as $file) {
        $src = \file_get_contents($file);
        // A prelude file is a FRAGMENT: it carries no opening tag, and
        // token_get_all would read the whole thing as inline HTML and find
        // nothing at all (a silent empty report, which is worse than an error).
        if (!\str_contains(\substr($src, 0, 200), '<?php')) { $src = "<?php\n" . $src; }
        foreach (scan_functions($src) as $name => $params) {
            $decls[$name][] = ['file' => \basename($file), 'params' => $params];
        }
    }
}

$rows = [];
foreach ($decls as $name => $sites) {
    if (!\function_exists($name)) { continue; }
    $rf = new ReflectionFunction($name);
    if (!$rf->isInternal()) { continue; }
    $zend = [];
    foreach ($rf->getParameters() as $p) { $zend[] = [$p->getName(), $p->isPassedByReference()]; }
    foreach ($sites as $site) {
        foreach (compare_params($zend, $site['params']) as $what) {
            $rows[] = [$name, $site['file'], $what];
        }
    }
}

\usort($rows, static fn (array $a, array $b): int => \strcmp($a[0], $b[0]));

$tsv = "# function\tfile\tdivergence\n";
foreach ($rows as $r) { $tsv .= $r[0] . "\t" . $r[1] . "\t" . $r[2] . "\n"; }
if ($out !== '') {
    \file_put_contents($out, $tsv);
    echo 'refmask: ', \count($rows), " divergence(s) -> $out\n";
} else {
    echo $tsv;
    echo '# ', \count($rows), " divergence(s)\n";
}

/**
 * Every global function declared at brace depth 0, as name => [[name, byRef], …].
 * @return array<string, array<int, array{0:string,1:bool}>>
 */
function scan_functions(string $src): array
{
    $toks = \token_get_all($src);
    $n = \count($toks);
    $found = [];
    $depth = 0;
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (\is_string($t)) {
            if ($t === '{') { $depth++; } elseif ($t === '}') { $depth--; }
            continue;
        }
        if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; continue; }
        if ($t[0] !== T_FUNCTION || $depth !== 0) { continue; }
        $j = $i + 1;
        while ($j < $n && \is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) { $j++; }
        // `function &f()` — a by-reference RETURN, not a parameter.
        if ($j < $n && \is_string($toks[$j]) && $toks[$j] === '&') {
            $j++;
            while ($j < $n && \is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) { $j++; }
        }
        if (!\is_array($toks[$j]) || $toks[$j][0] !== T_STRING) { continue; }
        $found[$toks[$j][1]] = scan_params($toks, $j + 1, $n);
    }
    return $found;
}

/**
 * The parameter list starting at the next `(` after $from.
 * @param array<int, mixed> $toks
 * @return array<int, array{0:string,1:bool}>
 */
function scan_params(array $toks, int $from, int $n): array
{
    $k = $from;
    while ($k < $n && !(\is_string($toks[$k]) && $toks[$k] === '(')) { $k++; }
    $par = 0;
    $params = [];
    $byRef = false;
    $seenVar = false;
    for ($m = $k; $m < $n; $m++) {
        $t = $toks[$m];
        if (\is_string($t)) {
            if ($t === '(') { $par++; continue; }
            if ($t === ')') { $par--; if ($par === 0) { break; } continue; }
            if ($par === 1 && $t === ',') { $byRef = false; $seenVar = false; }
            continue;
        }
        if ($par !== 1) { continue; }
        // php 8.1 splits `&` into two dedicated tokens; matching the plain
        // string '&' finds NOTHING and every `&$x` reads as by-value — the
        // first version of this scan reported 51 false divergences that way.
        if ($t[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
            || $t[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) { $byRef = true; }
        if ($t[0] === T_VARIABLE && !$seenVar) { $params[] = [$t[1], $byRef]; $seenVar = true; }
    }
    return $params;
}

/**
 * @param array<int, array{0:string,1:bool}> $zend
 * @param array<int, array{0:string,1:bool}> $ours
 * @return string[]
 */
function compare_params(array $zend, array $ours): array
{
    $out = [];
    foreach ($zend as $i => $z) {
        $o = $ours[$i] ?? null;
        if ($o === null) {
            // Only a missing BY-REF parameter matters: a missing by-value one
            // is an arity gap the caller sees as a TypeError, while a missing
            // by-ref one silently removes a definition from the caller.
            if ($z[1]) { $out[] = 'missing by-ref param #' . $i . ' $' . $z[0]; }
            continue;
        }
        if ($z[1] !== $o[1]) {
            $out[] = 'param #' . $i . ' ' . $o[0] . ': ours='
                . ($o[1] ? 'byref' : 'byval') . ' zend=' . ($z[1] ? 'byref' : 'byval');
        }
    }
    return $out;
}
