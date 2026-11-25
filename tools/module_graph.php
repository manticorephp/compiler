<?php
declare(strict_types=1);

/**
 * Dependency graph of a PHP tree at module (namespace) and class granularity,
 * and what its strongly connected components look like.
 *
 *   php tools/module_graph.php [--roots=DIR,...] [--edges=N] DIR...
 *
 * Unit = a class / interface / trait / enum, or a file's free functions
 * (`<file>#fns`). Module = the unit's namespace; free functions in the global
 * namespace get `<dir of file>` (prelude/, Stdlib/) since they have no other.
 * Edges come from tokens only (no type inference): every name that resolves,
 * through the file's namespace and `use` imports, to a declared class or
 * function — extends/implements/trait use, `new`, static calls, type hints,
 * `instanceof`, `X::class`, function calls — plus class names on docblock tag
 * lines (`@var`, `@param`, `@return`, ...). A `$x->m()` call is an edge only
 * through `$x`'s declared type. Over-approximates on docblock prose words that
 * happen to name a class.
 *
 * --roots: keep only units reachable from units declared under these dirs
 * (e.g. src, so prelude files the compiler never calls drop out).
 */

$dirs = [];
$roots = [];
$showEdges = 0;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--roots=')) { $roots = explode(',', substr($a, 8)); continue; }
    if (str_starts_with($a, '--edges=')) { $showEdges = (int)substr($a, 8); continue; }
    $dirs[] = rtrim($a, '/');
}
if ($dirs === []) { fwrite(STDERR, "usage: php tools/module_graph.php [--roots=DIR,...] DIR...\n"); exit(2); }

$files = [];
foreach ($dirs as $d) {
    if (is_file($d)) { $files[] = $d; continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) { $files[] = $f->getPathname(); }
    }
}
sort($files);

/** @var array<string, array{file:string, module:string, lines:int}> $units */
$units = [];
/** @var array<string, string> lower FQN class → unit */
$classUnit = [];
/** @var array<string, string> lower FQN function → unit */
$fnUnit = [];
/** @var list<array{unit:string, kind:string, name:string, ns:string, uses:array<string,string>, fuses:array<string,string>}> */
$refs = [];

const SKIP_PREV = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST,
    T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_GOTO, T_AS, T_CASE];
const DOC_TAGS = '/@(?:var|param|return|property(?:-read|-write)?|method|throws|template\w*|extends|implements|use|mixin|phpstan-\w+|psalm-\w+)\b(.*)$/m';

function sig(array $toks, int $i, int $dir): int
{
    for ($j = $i + $dir; $j >= 0 && $j < count($toks); $j += $dir) {
        $t = $toks[$j];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        return $j;
    }
    return -1;
}

function tid(mixed $t): int|string { return is_array($t) ? $t[0] : $t; }

foreach ($files as $file) {
    $src = file_get_contents($file);
    $toks = token_get_all($src);
    $n = count($toks);
    $ns = '';
    $uses = [];   // alias lower → FQN (classes)
    $fuses = [];  // alias lower → FQN (functions)
    $depth = 0;
    $classDepth = -1;
    $cur = null;
    $fnUnitName = $file . '#fns';
    $fileModule = dirname($file);
    $curStart = 0;
    $line = 1;
    $anonClass = false;
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        $id = tid($t);
        if (is_array($t)) { $line = $t[2]; }
        if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; continue; }
        if ($id === '}') {
            $depth--;
            if ($cur !== null && $depth === $classDepth) {
                $units[$cur]['lines'] += $line - $curStart + 1;
                $cur = null; $classDepth = -1;
            }
            continue;
        }
        $unit = $cur ?? $fnUnitName;
        if ($id === T_NAMESPACE) {
            $j = sig($toks, $i, 1);
            if ($j >= 0 && in_array(tid($toks[$j]), [T_STRING, T_NAME_QUALIFIED], true)) {
                $ns = $toks[$j][1]; $uses = []; $fuses = []; $i = $j;
            }
            continue;
        }
        if ($id === T_USE && $cur === null && $depth <= 1) {
            // import: use A\B [as C], use function A\b, group use A\{B, C}
            $kind = 'class';
            $j = sig($toks, $i, 1);
            if (tid($toks[$j]) === T_FUNCTION) { $kind = 'fn'; $j = sig($toks, $j, 1); }
            elseif (tid($toks[$j]) === T_CONST) { $kind = 'const'; $j = sig($toks, $j, 1); }
            $prefix = '';
            $buf = '';
            $alias = null;
            for (; $j < $n && tid($toks[$j]) !== ';'; $j++) {
                $tt = $toks[$j];
                $ti = tid($tt);
                if ($ti === '{') { $prefix = rtrim($buf, '\\') . '\\'; $buf = ''; continue; }
                if ($ti === T_AS) { $k = sig($toks, $j, 1); $alias = $toks[$k][1]; $j = $k; continue; }
                if ($ti === ',' || $ti === '}') {
                    if ($buf !== '') {
                        $fq = ltrim($prefix . $buf, '\\');
                        $al = strtolower($alias ?? substr($fq, (int)strrpos('\\' . $fq, '\\')));
                        if ($kind === 'class') { $uses[$al] = $fq; } elseif ($kind === 'fn') { $fuses[$al] = $fq; }
                    }
                    $buf = ''; $alias = null; continue;
                }
                if (in_array($ti, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) { $buf .= $tt[1]; }
            }
            if ($buf !== '') {
                $fq = ltrim($prefix . $buf, '\\');
                $al = strtolower($alias ?? substr($fq, (int)strrpos('\\' . $fq, '\\')));
                if ($kind === 'class') { $uses[$al] = $fq; } elseif ($kind === 'fn') { $fuses[$al] = $fq; }
            }
            $i = $j;
            continue;
        }
        if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            $p = sig($toks, $i, -1);
            if ($p >= 0 && in_array(tid($toks[$p]), [T_DOUBLE_COLON, T_NEW], true)) { continue; } // X::class, new class
            $j = sig($toks, $i, 1);
            if ($j < 0 || tid($toks[$j]) !== T_STRING) { continue; }
            if ($cur !== null) { continue; }
            $name = ($ns === '' ? '' : $ns . '\\') . $toks[$j][1];
            $cur = $name;
            $classDepth = $depth;
            $curStart = $toks[$j][2];
            $units[$name] ??= ['file' => $file, 'module' => $ns === '' ? $fileModule : $ns, 'lines' => 0];
            $classUnit[strtolower($name)] = $name;
            $i = $j;
            continue;
        }
        if ($id === T_FUNCTION && $cur === null) {
            $j = sig($toks, $i, 1);
            if ($j >= 0 && tid($toks[$j]) === '&') { $j = sig($toks, $j, 1); }
            if ($j >= 0 && tid($toks[$j]) === T_STRING) {
                $fq = ($ns === '' ? '' : $ns . '\\') . $toks[$j][1];
                $units[$fnUnitName] ??= ['file' => $file, 'module' => $ns === '' ? $fileModule : $ns, 'lines' => 0];
                $fnUnit[strtolower($fq)] = $fnUnitName;
                $i = $j;
            }
            continue;
        }
        if ($id === T_DOC_COMMENT) {
            if (preg_match_all(DOC_TAGS, $t[1], $m)) {
                foreach ($m[1] as $rest) {
                    if (preg_match_all('/(?<![\$\w\\\\])\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/', $rest, $mm)) {
                        foreach ($mm[0] as $nm) { $refs[] = [$unit, 'class', $nm, $ns, $uses, $fuses]; }
                    }
                }
            }
            continue;
        }
        if (!in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) { continue; }
        $p = sig($toks, $i, -1);
        if ($p >= 0 && in_array(tid($toks[$p]), SKIP_PREV, true)) { continue; }
        $q = sig($toks, $i, 1);
        $isCall = $q >= 0 && tid($toks[$q]) === '(' && !($p >= 0 && tid($toks[$p]) === T_NEW);
        $refs[] = [$unit, $isCall ? 'fn' : 'class', $t[1], $ns, $uses, $fuses];
    }
    if ($cur !== null) { $units[$cur]['lines'] += $line - $curStart + 1; }
    if (isset($units[$fnUnitName])) {
        // free functions: whole file minus class bodies
        $cls = 0;
        foreach ($units as $u => $info) { if ($info['file'] === $file && $u !== $fnUnitName) { $cls += $info['lines']; } }
        $units[$fnUnitName]['lines'] = max(1, substr_count($src, "\n") - $cls);
    }
}

function resolveClass(string $nm, string $ns, array $uses): string
{
    if ($nm[0] === '\\') { return substr($nm, 1); }
    if (str_starts_with(strtolower($nm), 'namespace\\')) { return ($ns === '' ? '' : $ns . '\\') . substr($nm, 10); }
    $first = strtolower(explode('\\', $nm)[0]);
    if (isset($uses[$first])) {
        $rest = substr($nm, strlen($first));
        return $uses[$first] . $rest;
    }
    return ($ns === '' ? '' : $ns . '\\') . $nm;
}

/** @var array<string, array<string, true>> */
$adj = [];
foreach ($units as $u => $_) { $adj[$u] = []; }
foreach ($refs as [$unit, $kind, $nm, $ns, $uses, $fuses]) {
    if (!isset($units[$unit])) {
        // refs before any declaration in a file of free functions
        $units[$unit] = ['file' => explode('#', $unit)[0], 'module' => $ns === '' ? dirname(explode('#', $unit)[0]) : $ns, 'lines' => 1];
        $adj[$unit] = [];
    }
    $low = strtolower($nm);
    if (in_array($low, ['self', 'static', 'parent', 'true', 'false', 'null'], true)) { continue; }
    $target = null;
    if ($kind === 'fn') {
        if ($nm[0] === '\\') { $target = $fnUnit[strtolower(substr($nm, 1))] ?? null; }
        elseif (!str_contains($nm, '\\') && isset($fuses[$low])) { $target = $fnUnit[strtolower($fuses[$low])] ?? null; }
        else {
            $target = $fnUnit[strtolower(resolveClass($nm, $ns, $uses))] ?? (str_contains($nm, '\\') ? null : ($fnUnit[$low] ?? null));
        }
    } else {
        $target = $classUnit[strtolower(resolveClass($nm, $ns, $uses))] ?? null;
    }
    if ($target !== null && $target !== $unit) { $adj[$unit][$target] = true; }
}

if ($roots !== []) {
    $seen = [];
    $stack = [];
    foreach ($units as $u => $info) {
        foreach ($roots as $r) {
            if (str_starts_with($info['file'], rtrim($r, '/') . '/')) { $stack[] = $u; $seen[$u] = true; break; }
        }
    }
    while ($stack !== []) {
        $u = array_pop($stack);
        foreach ($adj[$u] as $v => $_) { if (!isset($seen[$v])) { $seen[$v] = true; $stack[] = $v; } }
    }
    $units = array_intersect_key($units, $seen);
    $adj = array_intersect_key($adj, $seen);
}

/**
 * Iterative Tarjan. @param array<string, array<string, true>> $g
 * @return list<list<string>> SCCs in reverse topological order (sinks first)
 */
function tarjan(array $g): array
{
    $index = []; $low = []; $on = []; $st = []; $out = []; $idx = 0;
    foreach ($g as $root => $_) {
        if (isset($index[$root])) { continue; }
        $work = [[$root, array_keys($g[$root]), 0]];
        $index[$root] = $low[$root] = $idx++; $st[] = $root; $on[$root] = true;
        while ($work !== []) {
            $top = count($work) - 1;
            [$v, $succ, $k] = $work[$top];
            if ($k < count($succ)) {
                $work[$top][2]++;
                $w = $succ[$k];
                if (!isset($g[$w])) { continue; }
                if (!isset($index[$w])) {
                    $index[$w] = $low[$w] = $idx++; $st[] = $w; $on[$w] = true;
                    $work[] = [$w, array_keys($g[$w]), 0];
                } elseif (isset($on[$w])) {
                    $low[$v] = min($low[$v], $index[$w]);
                }
                continue;
            }
            array_pop($work);
            if ($work !== []) { $p = $work[count($work) - 1][0]; $low[$p] = min($low[$p], $low[$v]); }
            if ($low[$v] === $index[$v]) {
                $c = [];
                do { $w = array_pop($st); unset($on[$w]); $c[] = $w; } while ($w !== $v);
                $out[] = $c;
            }
        }
    }
    return $out;
}

/**
 * @param array<string, array<string, true>> $g
 * @param array<string, int> $weight
 */
function report(string $title, array $g, array $weight, int $showEdges): void
{
    $sccs = tarjan($g);
    $comp = [];
    foreach ($sccs as $ci => $c) { foreach ($c as $v) { $comp[$v] = $ci; } }
    $total = array_sum($weight);
    usort($sccs, fn($a, $b) => count($b) <=> count($a));
    $nontriv = array_values(array_filter($sccs, fn($c) => count($c) > 1));
    // condensation: longest path in nodes and in weight (sinks first order is tarjan's own)
    $raw = tarjan($g);
    $comp = [];
    foreach ($raw as $ci => $c) { foreach ($c as $v) { $comp[$v] = $ci; } }
    $depthN = []; $depthW = []; $edges = 0;
    foreach ($raw as $ci => $c) {
        $w = 0; foreach ($c as $v) { $w += $weight[$v]; }
        $bestN = 0; $bestW = 0;
        foreach ($c as $v) {
            foreach ($g[$v] as $s => $_) {
                $edges++;
                $cs = $comp[$s];
                if ($cs === $ci) { continue; }
                $bestN = max($bestN, $depthN[$cs]); $bestW = max($bestW, $depthW[$cs]);
            }
        }
        $depthN[$ci] = $bestN + 1; $depthW[$ci] = $bestW + $w;
    }
    $big = $sccs[0];
    $bigW = 0; foreach ($big as $v) { $bigW += $weight[$v]; }
    printf("== %s ==\n", $title);
    printf("nodes %d  edges %d  lines %d\n", count($g), $edges, $total);
    printf("SCCs %d (non-trivial %d)\n", count($raw), count($nontriv));
    printf("largest SCC: %d nodes, %d lines (%.1f%% of lines)\n", count($big), $bigW, $total > 0 ? 100 * $bigW / $total : 0);
    for ($k = 1; $k < min(6, count($nontriv)); $k++) {
        $w = 0; foreach ($nontriv[$k] as $v) { $w += $weight[$v]; }
        printf("  next SCC: %d nodes, %d lines\n", count($nontriv[$k]), $w);
    }
    printf("critical path: %d SCCs deep, %d lines on it (%.1f%%)\n", max($depthN), max($depthW), $total > 0 ? 100 * max($depthW) / $total : 0);
    $outside = $total - $bigW;
    printf("lines outside the largest SCC: %d (%.1f%%)\n", $outside, $total > 0 ? 100 * $outside / $total : 0);
    if ($showEdges > 0 && count($big) <= 200) {
        sort($big);
        printf("largest SCC members:\n  %s\n", implode("\n  ", array_slice($big, 0, $showEdges)));
    }
    echo "\n";
}

$weight = [];
foreach ($units as $u => $info) { $weight[$u] = $info['lines']; }
report('unit (class / file free functions)', $adj, $weight, 0);

$madj = [];
$mweight = [];
$mcount = [];
foreach ($units as $u => $info) {
    $m = $info['module'];
    $madj[$m] ??= [];
    $mweight[$m] = ($mweight[$m] ?? 0) + $info['lines'];
    $mcount[$m] = ($mcount[$m] ?? 0) + 1;
}
foreach ($adj as $u => $succ) {
    $mu = $units[$u]['module'];
    foreach ($succ as $v => $_) {
        $mv = $units[$v]['module'];
        if ($mv !== $mu) { $madj[$mu][$mv] = true; }
    }
}
report('module (namespace)', $madj, $mweight, $showEdges);

if ($showEdges > 0) {
    arsort($mweight);
    echo "modules by lines:\n";
    foreach ($mweight as $m => $w) { printf("  %7d  %4d units  %3d out-edges  %s\n", $w, $mcount[$m], count($madj[$m]), $m); }
}

if ($showEdges > 0) {
    echo "\nmodule edges (unit-edge count, example):\n";
    $me = [];
    foreach ($adj as $u => $succ) {
        $mu = $units[$u]['module'];
        foreach ($succ as $v => $_) {
            $mv = $units[$v]['module'];
            if ($mv === $mu) { continue; }
            $me["$mu -> $mv"] ??= [0, "$u -> $v"];
            $me["$mu -> $mv"][0]++;
        }
    }
    ksort($me);
    foreach ($me as $k => [$c, $ex]) { printf("  %-50s %4d  %s\n", $k, $c, $ex); }
    echo "\nlargest unit SCC by lines:\n";
    $sccs = tarjan($adj);
    usort($sccs, fn($a, $b) => count($b) <=> count($a));
    $big = $sccs[0];
    usort($big, fn($a, $b) => $units[$b]['lines'] <=> $units[$a]['lines']);
    foreach (array_slice($big, 0, $showEdges) as $u) { printf("  %7d  %s\n", $units[$u]['lines'], $u); }
}
