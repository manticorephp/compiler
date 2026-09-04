<?php
/**
 * alias_scan.php — enumerate every PHP builtin name that a namespaced stdlib
 * extern CAPTURES through the bare-name alias rule.
 *
 * `LowerFromAst::lowerProgram` registers, for every injected `.o.sig` extern
 * whose name is namespaced and whose bare name is unique, an alias
 * `bare -> Full\Name` (LowerFromAst.php:822-841). `resolveCallName`
 * (LowerFromAst.php:1826) then resolves an unqualified call in this order:
 *
 *     fnDecls[full] -> fnDecls[bare] -> fnAliasByBare[bare] -> bare
 *
 * So when the sig carries `Runtime\Libc\strstr` (a raw C binding) and carries
 * NO global `strstr`, user code writing `strstr($h, $n)` binds to C's
 * `strstr(const char*, int)`. It compiles, it links, it runs — and it returns
 * a raw pointer where PHP returns a string. That is the S0 class: a silent
 * wrong answer, invisible to both the analyzer and the linker.
 *
 * A name is CAPTURED when all of:
 *   - it is a real PHP internal function (Zend is the oracle);
 *   - the sig has no global-namespace declaration of it (else that wins);
 *   - it is not a codegen builtin (those never register an alias);
 *   - exactly one namespaced sig decl has that bare name (two would collide
 *     to '' and disable the alias).
 *
 * Usage: php tools/audit/alias_scan.php [--sig <path>] [--out <path>]
 * Exit:  0 = no captures, 1 = captures found (this is an audit, not a gate;
 *        the caller decides what to do with the code).
 */

$sigPath = 'lib/manticore_stdlib.o.sig';
$outPath = 'tests/audit/data/alias-capture.tsv';
$lowerFns = 'src/Compile/Mir/Passes/LowerFns.php';

$argvv = $argv;
for ($i = 1; $i < count($argvv); $i++) {
    if ($argvv[$i] === '--sig' && isset($argvv[$i + 1])) { $sigPath = $argvv[++$i]; }
    elseif ($argvv[$i] === '--out' && isset($argvv[$i + 1])) { $outPath = $argvv[++$i]; }
}

if (!is_file($sigPath)) { fwrite(STDERR, "alias_scan: no sig at $sigPath\n"); exit(2); }
$sig = json_decode((string)file_get_contents($sigPath), true);
if (!is_array($sig) || !isset($sig['functions'])) {
    fwrite(STDERR, "alias_scan: malformed sig at $sigPath\n"); exit(2);
}

/**
 * The codegen-builtin set, DERIVED from the dispatch rather than copied.
 * `LowerFns::isCodegenBuiltin` strips the namespace before comparing, so the
 * bare name is what matters — and a match there makes `lowerProgram` skip the
 * extern entirely, alias included.
 */
function codegen_builtins(string $lowerFnsPath): array
{
    $src = (string)@file_get_contents($lowerFnsPath);
    $start = strpos($src, 'function isCodegenBuiltin');
    if ($start === false) { return []; }
    $end = strpos($src, "\n    }", $start);
    $body = substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
    $m = [];
    preg_match_all("/\\\$n === '([^']+)'/", $body, $m);
    return array_fill_keys($m[1], true);
}

$builtins = codegen_builtins($lowerFns);

/**
 * Every function in the tree that is a RAW C BINDING, keyed by fully-qualified
 * name. This is the line that separates a benign capture from an S0 one: a
 * `Runtime\Stdlib\*` capture is the design (those ARE the PHP-semantics
 * implementations, written in PHP), while a `Runtime\Libc\*` capture hands
 * user code the C function under a PHP name.
 *
 * Detected structurally — `#[...Symbol('sym')...]` immediately preceding a
 * `function` — not by namespace prefix, so a future FFI binding anywhere is
 * still caught.
 *
 * @return array<string,string> fqn -> C symbol
 */
function ffi_bindings(array $roots): array
{
    $out = [];
    foreach ($roots as $root) {
        if (!is_dir($root)) { continue; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
            $src = (string)file_get_contents($f->getPathname());
            $ns = '';
            if (preg_match('/^\s*namespace\s+([^;{\s]+)/m', $src, $nm)) { $ns = $nm[1]; }
            $m = [];
            preg_match_all(
                "/#\\[[^\\]]*Symbol\\('([^']+)'\\)[^\\]]*\\][\\s\\S]{0,200}?function\\s+([A-Za-z_][A-Za-z0-9_]*)/",
                $src, $m, PREG_SET_ORDER
            );
            foreach ($m as $hit) {
                $fqn = ($ns === '' ? '' : $ns . '\\') . $hit[2];
                $out[$fqn] = $hit[1];
            }
        }
    }
    return $out;
}

$ffi = ffi_bindings(['src/Runtime', 'src/Manticore', 'prelude', 'ext']);

/** @var array<string,true> $globals bare names the sig declares globally */
$globals = [];
/** @var array<string,string[]> $nsByBare bare name -> namespaced full names */
$nsByBare = [];
/** @var array<string,array> $declByName */
$declByName = [];

foreach ($sig['functions'] as $decl) {
    $name = (string)($decl['name'] ?? '');
    if ($name === '') { continue; }
    $declByName[$name] = $decl;
    $pos = strrpos($name, '\\');
    if ($pos === false) { $globals[strtolower($name)] = true; continue; }
    $bare = strtolower(substr($name, $pos + 1));
    // Mirrors the `isCodegenBuiltin` skip in LowerFromAst.php:825 — a builtin
    // extern is dropped before the alias is ever registered.
    if (isset($builtins[$bare])) { continue; }
    if (!isset($nsByBare[$bare])) { $nsByBare[$bare] = []; }
    $nsByBare[$bare][] = $name;
}

$internal = array_fill_keys(get_defined_functions()['internal'], true);

/** Render a sig decl as a compact prototype. */
function sig_proto(array $d): string
{
    $ps = [];
    foreach (($d['params'] ?? []) as $p) {
        $t = (string)($p['type'] ?? 'mixed');
        $ps[] = ($p['byref'] ?? false ? '&' : '') . $t . ($p['variadic'] ?? false ? '...' : '');
    }
    return '(' . implode(',', $ps) . '):' . (string)($d['ret'] ?? 'mixed');
}

/** Render the Zend prototype for the same name. */
function php_proto(string $fn): string
{
    try {
        $r = new ReflectionFunction($fn);
        $ps = [];
        foreach ($r->getParameters() as $p) {
            $t = $p->getType();
            $ps[] = ($p->isPassedByReference() ? '&' : '')
                . ($t === null ? 'mixed' : (string)$t)
                . ($p->isVariadic() ? '...' : '');
        }
        $rt = $r->getReturnType();
        return '(' . implode(',', $ps) . '):' . ($rt === null ? 'mixed' : (string)$rt);
    } catch (Throwable $e) {
        return '(?):?';
    }
}

$rows = [];
foreach ($nsByBare as $bare => $fulls) {
    if (!isset($internal[$bare])) { continue; }       // not a PHP function — nobody calls it by that name
    if (isset($globals[$bare])) { continue; }         // a global sig decl wins at resolveCallName:1831
    if (count($fulls) !== 1) { continue; }            // two decls collide to '' — alias disabled
    $full = $fulls[0];
    $d = $declByName[$full];
    $mc = sig_proto($d);
    $php = php_proto($bare);
    // A capture whose return type cannot represent PHP's is the dangerous
    // shape: PHP hands back a string, the binding hands back a pointer or an
    // int. Same-shape captures may still diverge semantically, so they are
    // reported too, one class down.
    $mcRet = (string)($d['ret'] ?? 'mixed');
    $phpRet = 'mixed';
    try { $rt = (new ReflectionFunction($bare))->getReturnType(); if ($rt !== null) { $phpRet = (string)$rt; } }
    catch (Throwable $e) {}
    $retMismatch = normalize_ret($mcRet) !== normalize_ret($phpRet);
    $isFfi = isset($ffi[$full]);
    if (!$isFfi) {
        // A PHP-semantics implementation under a namespace. The alias is how
        // the stdlib is meant to reach user code — not a finding.
        $risk = 'BY-DESIGN-php-impl';
    } elseif ($retMismatch) {
        // C hands back a pointer/int where PHP hands back a string or array.
        // Compiles, links, runs, lies.
        $risk = 'S0-ret-shape';
    } else {
        // Same return shape, but C string semantics are NUL-terminated while
        // PHP strings are length-counted — divergence on any embedded \x00.
        $risk = 'S0-c-semantics';
    }
    $rows[] = [$bare, $full, $isFfi ? $ffi[$full] : '-', $mc, $php, $risk];
}

/** Collapse the two type vocabularies enough to compare return shapes. */
function normalize_ret(string $t): string
{
    $t = strtolower(ltrim($t, '?\\'));
    if ($t === 'ffi\\ptr' || $t === 'ptr') { return 'ptr'; }
    if ($t === 'integer' || $t === 'long') { return 'int'; }
    // PHP's `string|false` and a binding's `string` are the same shape for
    // this purpose — the interesting mismatch is ptr/int vs string/array.
    $t = str_replace(['|false', '|null'], '', $t);
    return $t;
}

usort($rows, function ($a, $b) { return ($a[5] === $b[5]) ? strcmp($a[0], $b[0]) : strcmp($a[5], $b[5]); });

$dir = dirname($outPath);
if (!is_dir($dir)) { mkdir($dir, 0777, true); }
$fh = fopen($outPath, 'w');
fwrite($fh, "bare_name\tbound_to\tc_symbol\tmanticore_proto\tphp_proto\trisk\n");
foreach ($rows as $r) { fwrite($fh, implode("\t", $r) . "\n"); }
fclose($fh);

$s0 = 0;
foreach ($rows as $r) { if (str_starts_with($r[5], 'S0')) { $s0++; } }

fwrite(STDERR, sprintf(
    "alias_scan: %d sig functions, %d global, %d aliasable bare names, %d FFI bindings\n"
    . "alias_scan: %d captures (%d S0) -> %s\n",
    count($sig['functions']), count($globals), count($nsByBare), count($ffi),
    count($rows), $s0, $outPath
));
foreach ($rows as $r) { fwrite(STDERR, "  {$r[5]}\t{$r[0]}() -> {$r[1]}" . ($r[2] === '-' ? '' : " [C {$r[2]}]") . "\n"); }

exit($s0 > 0 ? 1 : 0);
