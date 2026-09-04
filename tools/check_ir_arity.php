<?php

/**
 * Report call sites whose ARGUMENT COUNT disagrees with the callee's definition,
 * over emitted LLVM IR.
 *
 *   php tools/compile_user_mir.php prog.php > prog.ll
 *   php tools/check_ir_arity.php prog.ll
 *
 * Why this exists: clang accepts a direct call with FEWER arguments than the
 * definition without a word, and the callee then reads whatever the ABI's
 * argument registers happened to hold. That is how `$a + $b` on two arrays with
 * a shared string key came to answer the RIGHT side's value: the union helper
 * called `__mir_array_isset_str` (4 params) with 2 arguments, read garbage for
 * `hash` / `haveHash`, and concluded the key was absent every time. Three such
 * call sites were live — two in the array runtime, one in the array comparator.
 *
 * WARNING: run it PER FILE. Two programs can hold different functions under one
 * name (closures are numbered per module, a user class is only as unique as the
 * program), so merging several .ll files into one namespace invents mismatches.
 * The `__mir_*` runtime helpers are the exception — identical in every module.
 *
 * Exit 1 when something is reported, so it can gate a build.
 */

/** @param string $args the text between a call's parentheses */
function ir_arity_count(string $args): int
{
    if (\trim($args) === '') {
        return 0;
    }
    $n = 1;
    $depth = 0;
    $len = \strlen($args);
    for ($i = 0; $i < $len; $i++) {
        $c = $args[$i];
        if ($c === '(' || $c === '[' || $c === '{') {
            $depth++;
        } elseif ($c === ')' || $c === ']' || $c === '}') {
            $depth--;
        } elseif ($c === ',' && $depth === 0) {
            $n++;
        }
    }
    return $n;
}

$files = \array_slice($argv, 1);
if ($files === []) {
    \fwrite(\STDERR, "usage: php tools/check_ir_arity.php <module.ll> [more.ll ...]\n");
    exit(2);
}

$lines = [];
foreach ($files as $f) {
    $h = @\fopen($f, 'r');
    if ($h === false) {
        \fwrite(\STDERR, "cannot read $f\n");
        exit(2);
    }
    while (($l = \fgets($h)) !== false) {
        $lines[] = $l;
    }
    \fclose($h);
}

/** @var array<string,int> $def */
$def = [];
/** @var array<string,bool> $vararg */
$vararg = [];
foreach ($lines as $l) {
    if (\preg_match('/^(?:define|declare)[^@]*@([\w.]+)\s*\((.*?)\)\s*(?:#\d+\s*)?\{?\s*$/', $l, $m) !== 1) {
        continue;
    }
    [, $name, $params] = $m;
    if (\str_starts_with($name, 'llvm.')) {
        continue;
    }
    if (\str_contains($params, '...')) {
        $vararg[$name] = true;
    }
    if (!isset($def[$name])) {
        $def[$name] = ir_arity_count($params);
    }
}

/** @var array<string,int> $bad */
$bad = [];
foreach ($lines as $l) {
    if (\preg_match('/\bcall\b.*?@([\w.]+)\s*\((.*)\)\s*$/', $l, $m) !== 1) {
        continue;
    }
    [, $name, $args] = $m;
    if (\str_starts_with($name, 'llvm.') || isset($vararg[$name]) || !isset($def[$name])) {
        continue;
    }
    $n = ir_arity_count($args);
    if ($n !== $def[$name]) {
        $key = $name . ': def=' . $def[$name] . ' call=' . $n;
        $bad[$key] = ($bad[$key] ?? 0) + 1;
    }
}

if ($bad === []) {
    echo "no arity mismatches\n";
    exit(0);
}
\ksort($bad);
foreach ($bad as $k => $count) {
    echo $k, '  (x', $count, ")\n";
}
exit(1);
