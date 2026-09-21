<?php
declare(strict_types=1);

/**
 * Builtin coverage audit — regenerates docs/builtins.md.
 *
 *   php tools/builtins_audit.php            # write docs/builtins.md
 *   php tools/builtins_audit.php --stdout   # print instead
 *
 * Runs under Zend and reads SOURCES, not build artifacts, so it needs no
 * bin/manticore. The php that runs it is the reference: every internal
 * function / class it declares (minus a few non-bundled extensions) is
 * compared against what Manticore ships in its three tiers —
 *
 *   b  codegen builtin   EmitLlvmBuiltins::emitBuiltinDispatch ($name === '…')
 *   l  lowered           Analyze\Builtins::functionNames() plus every
 *                        `$fnBare === '…'` / `$fn === '…'` in Lower*.php, minus b:
 *                        resolved by LowerFromAst / the parser (define, trigger_error, …)
 *   s  stdlib            src/Runtime/Stdlib/*.php, global-namespace functions
 *   p  prelude           prelude/*.php + src/Runtime/*.php, injected per program
 *   i  intrinsic class   Analyze\Builtins::isKnownClass(): engine-provided, no PHP body
 *
 * Presence is not parity: tools/difftest.sh is the gate for behaviour.
 */

$root = \dirname(__DIR__);
$toStdout = \in_array('--stdout', $argv, true);

/** Extensions loaded on the host php that are not part of a PHP release. */
const NON_BUNDLED = ['xdebug', 'openswoole', 'Zend OPcache', 'mysqlnd'];

/** @var array<string, string> lowercase name => tier letter (first wins: b > s > p) */
$ours = [];
/** @var array<string, string> lowercase name => declared spelling */
$spell = [];
/** @var array<string, array{kind:string, ns:string, file:string}> lowercase FQN => class-like */
$ourTypes = [];
/** @var array<string, list<string>> namespace => function FQNs */
$nsFns = [];

function note(array &$ours, array &$spell, string $name, string $tier): void {
    $k = \strtolower($name);
    if (!isset($ours[$k])) { $ours[$k] = $tier; $spell[$k] = $name; }
}

// ── tier b: codegen builtins ────────────────────────────────────────────
$emit = (string)\file_get_contents($root . '/src/Compile/Mir/Passes/EmitLlvmBuiltins.php');
$start = \strpos($emit, 'function emitBuiltinDispatch(');
if ($start === false) { \fwrite(STDERR, "emitBuiltinDispatch not found\n"); exit(1); }
$end = \strpos($emit, "\n    private function ", $start + 10);
$body = \substr($emit, $start, ($end === false ? \strlen($emit) : $end) - $start);
\preg_match_all("/\\\$name === '([a-z_0-9\\\\]+)'/i", $body, $m);
foreach ($m[1] as $n) { note($ours, $spell, \ltrim($n, '\\'), 'b'); }
$builtinCount = \count(\array_unique(\array_map('strtolower', $m[1])));

// ── tier l: names the compiler resolves without a body ──────────────────
require_once $root . '/src/Analyze/Builtins.php';
foreach (\Analyze\Builtins::functionNames() as $n) { note($ours, $spell, $n, 'l'); }
foreach (\glob($root . '/src/Compile/Mir/Passes/Lower*.php') ?: [] as $lf) {
    \preg_match_all("/\\\$(?:fnBare|fn) === '([a-z_0-9]+)'/", (string)\file_get_contents($lf), $lm);
    foreach ($lm[1] as $n) { note($ours, $spell, $n, 'l'); }
}

// ── tiers s and p: tokenize sources ─────────────────────────────────────
/**
 * @return array{fns: list<array{ns:string,name:string}>, types: list<array{ns:string,name:string,kind:string}>}
 */
function scanFile(string $path): array {
    $toks = \token_get_all((string)\file_get_contents($path));
    $ns = ''; $nsDepth = 0; $depth = 0;
    $fns = []; $types = [];
    $n = \count($toks);
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (\is_string($t)) {
            if ($t === '{') { $depth++; }
            elseif ($t === '}') { $depth--; if ($nsDepth > 0 && $depth < $nsDepth) { $ns = ''; $nsDepth = 0; } }
            continue;
        }
        [$id, $text] = $t;
        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; continue; }
        if ($id === T_NAMESPACE) {
            $j = $i + 1; $name = '';
            while ($j < $n && (\is_array($toks[$j]) && \in_array($toks[$j][0], [T_WHITESPACE, T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true))) {
                if ($toks[$j][0] !== T_WHITESPACE) { $name .= $toks[$j][1]; }
                $j++;
            }
            $ns = $name;
            $nsDepth = ($j < $n && $toks[$j] === '{') ? $depth + 1 : 0;
            $i = $j - 1;
            continue;
        }
        $top = $depth === $nsDepth;
        if (!$top) { continue; }
        if ($id === T_FUNCTION) {
            $j = $i + 1;
            while ($j < $n && \is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) { $j++; }
            if ($j < $n && $toks[$j] === '&') { $j++; while ($j < $n && \is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) { $j++; } }
            if ($j < $n && \is_array($toks[$j]) && $toks[$j][0] === T_STRING) {
                $fns[] = ['ns' => $ns, 'name' => $toks[$j][1]];
            }
            continue;
        }
        if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
            $prev = $i - 1;
            while ($prev >= 0 && \is_array($toks[$prev]) && $toks[$prev][0] === T_WHITESPACE) { $prev--; }
            if ($prev >= 0 && \is_array($toks[$prev]) && \in_array($toks[$prev][0], [T_DOUBLE_COLON, T_NEW], true)) { continue; }
            $j = $i + 1;
            while ($j < $n && \is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) { $j++; }
            if ($j < $n && \is_array($toks[$j]) && $toks[$j][0] === T_STRING) {
                $kind = match ($id) { T_CLASS => 'class', T_INTERFACE => 'interface', T_TRAIT => 'trait', default => 'enum' };
                $types[] = ['ns' => $ns, 'name' => $toks[$j][1], 'kind' => $kind];
            }
        }
    }
    return ['fns' => $fns, 'types' => $types];
}

$stdlibFiles = \glob($root . '/src/Runtime/Stdlib/*.php') ?: [];
$preludeFiles = \array_merge(\glob($root . '/prelude/*.php') ?: [], \glob($root . '/src/Runtime/*.php') ?: []);
\sort($stdlibFiles); \sort($preludeFiles);

foreach ([['s', $stdlibFiles], ['p', $preludeFiles]] as [$tier, $files]) {
    foreach ($files as $f) {
        $rel = \substr($f, \strlen($root) + 1);
        $r = scanFile($f);
        foreach ($r['fns'] as $fn) {
            if ($fn['ns'] === '') { note($ours, $spell, $fn['name'], $tier); }
            else { $nsFns[$fn['ns']][] = $fn['ns'] . '\\' . $fn['name']; }
        }
        foreach ($r['types'] as $ty) {
            $fqn = ($ty['ns'] === '' ? '' : $ty['ns'] . '\\') . $ty['name'];
            $k = \strtolower($fqn);
            if (!isset($ourTypes[$k])) { $ourTypes[$k] = ['kind' => $ty['kind'], 'ns' => $ty['ns'], 'file' => $rel, 'name' => $fqn]; }
        }
    }
}

// ── reference: this php ─────────────────────────────────────────────────
/** @var array<string, array<string, true>> ext => lowercase fn => true */
$phpFns = [];
foreach (\get_defined_functions()['internal'] as $fn) {
    $ext = (new \ReflectionFunction($fn))->getExtensionName() ?: 'Core';
    if (\in_array($ext, NON_BUNDLED, true)) { continue; }
    $phpFns[$ext][\strtolower($fn)] = true;
}
/** @var array<string, array<string, string>> ext => lowercase class => kind */
$phpTypes = [];
foreach (\array_merge(\get_declared_classes(), \get_declared_interfaces(), \get_declared_traits()) as $c) {
    $rc = new \ReflectionClass($c);
    if (!$rc->isInternal()) { continue; }
    $ext = $rc->getExtensionName() ?: 'Core';
    if (\in_array($ext, NON_BUNDLED, true)) { continue; }
    $kind = $rc->isEnum() ? 'enum' : ($rc->isInterface() ? 'interface' : ($rc->isTrait() ? 'trait' : 'class'));
    $phpTypes[$ext][\strtolower($c)] = $kind;
}
$exts = \array_unique(\array_merge(\array_keys($phpFns), \array_keys($phpTypes)));
\usort($exts, fn ($a, $b) => \strcasecmp($a, $b));

// ── report ──────────────────────────────────────────────────────────────
$out = [];
$out[] = '# Builtin coverage — what Manticore ships of PHP, and what it adds';
$out[] = '';
$out[] = '_Generated by `php tools/builtins_audit.php` against PHP ' . \PHP_VERSION . ' on ' . \date('Y-m-d') . '. Do not edit by hand._';
$out[] = '';
$out[] = 'Every internal function and class the reference `php` declares (its bundled';
$out[] = 'extensions; ' . \implode(', ', NON_BUNDLED) . ' excluded) is checked against';
$out[] = 'what Manticore defines in its three tiers:';
$out[] = '';
$out[] = '| Tier | Where | What it means |';
$out[] = '|---|---|---|';
$out[] = '| `b` | `src/Compile/Mir/Passes/EmitLlvmBuiltins.php` | a codegen builtin — emitted inline as LLVM IR / a libc call |
| `l` | `src/Compile/Mir/Passes/Lower*.php`, the parser | lowered: resolved at compile time with no runtime body (`define`, `func_get_args`, `compact`, …) |';
$out[] = '| `s` | `src/Runtime/Stdlib/*.php` → `lib/manticore_stdlib.o` | written in PHP, compiled once, auto-linked when called |';
$out[] = '| `p` | `prelude/*.php`, `src/Runtime/*.php` | PHP injected into every program that mentions it |
| `i` | `src/Analyze/Builtins.php` | an intrinsic class the engine provides with no PHP body (`Closure`, `Generator`, `Fiber`, …) |';
$out[] = '';
$out[] = '**Presence is not parity.** A name in the implemented column means the call';
$out[] = 'compiles and runs; behaviour is graded by `tools/difftest.sh` against `php`.';
$out[] = 'A name in the missing column compiles (the call resolves to nothing) and traps';
$out[] = 'at run time with `Call to undefined function`.';
$out[] = '';

$totF = 0; $hitF = 0; $totC = 0; $hitC = 0;
$rows = []; $sections = [];
foreach ($exts as $ext) {
    $fns = $phpFns[$ext] ?? []; $tys = $phpTypes[$ext] ?? [];
    \ksort($fns); \ksort($tys);
    $have = []; $miss = [];
    foreach ($fns as $k => $_) { if (isset($ours[$k])) { $have[] = '`' . $k . '`<sup>' . $ours[$k] . '</sup>'; } else { $miss[] = '`' . $k . '`'; } }
    $haveC = []; $missC = [];
    foreach ($tys as $k => $kind) {
        $name = (new \ReflectionClass($k))->getName();
        if (isset($ourTypes[$k])) { $haveC[] = '`' . $name . '`'; }
        elseif (\Analyze\Builtins::isKnownClass($k)) { $haveC[] = '`' . $name . '`<sup>i</sup>'; }
        else { $missC[] = '`' . $name . '`'; }
    }
    $nf = \count($fns); $hf = \count($have); $nc = \count($tys); $hc = \count($haveC);
    $totF += $nf; $hitF += $hf; $totC += $nc; $hitC += $hc;
    if ($nf + $nc === 0) { continue; }
    $pct = $nf + $nc > 0 ? (int)\round(100 * ($hf + $hc) / ($nf + $nc)) : 0;
    $anchor = \strtolower(\preg_replace('/[^a-z0-9]+/i', '-', $ext));
    $rows[] = '| [' . $ext . '](#' . $anchor . ') | ' . $hf . ' / ' . $nf . ' | ' . $hc . ' / ' . $nc . ' | ' . $pct . '% |';
    $s = [];
    $s[] = '### ' . $ext;
    $s[] = '';
    $s[] = 'Functions ' . $hf . ' / ' . $nf . ' · classes ' . $hc . ' / ' . $nc;
    $s[] = '';
    if ($have !== [] || $haveC !== []) {
        $s[] = '<details><summary>implemented (' . ($hf + $hc) . ')</summary>';
        $s[] = '';
        if ($have !== []) { $s[] = \implode(' ', $have); $s[] = ''; }
        if ($haveC !== []) { $s[] = 'Classes: ' . \implode(' ', $haveC); $s[] = ''; }
        $s[] = '</details>';
        $s[] = '';
    }
    if ($miss !== [] || $missC !== []) {
        $s[] = '<details><summary>missing (' . (\count($miss) + \count($missC)) . ')</summary>';
        $s[] = '';
        if ($miss !== []) { $s[] = \implode(' ', $miss); $s[] = ''; }
        if ($missC !== []) { $s[] = 'Classes: ' . \implode(' ', $missC); $s[] = ''; }
        $s[] = '</details>';
        $s[] = '';
    }
    $sections[] = \implode("\n", $s);
}

$out[] = '## Summary';
$out[] = '';
$out[] = 'Functions **' . $hitF . ' / ' . $totF . '** · classes **' . $hitC . ' / ' . $totC . '**'
    . ' · codegen builtins ' . $builtinCount . ' · lowered ' . \count(\array_filter($ours, fn ($t) => $t === 'l')) . ' · stdlib globals ' . \count(\array_filter($ours, fn ($t) => $t === 's'))
    . ' · prelude globals ' . \count(\array_filter($ours, fn ($t) => $t === 'p'));
$out[] = '';
$out[] = '| Extension | Functions | Classes | Coverage |';
$out[] = '|---|---|---|---|';
foreach ($rows as $r) { $out[] = $r; }
$out[] = '';

// ── Manticore-only surface ──────────────────────────────────────────────
$out[] = '## Beyond PHP — what Manticore adds';
$out[] = '';
$out[] = 'Nothing here has a Zend oracle; each namespace is specified by its own';
$out[] = 'document under `docs/` and tested by its own cases. See [`superset.md`](superset.md).';
$out[] = '';
$docLinks = [
    'Async' => 'async.md', 'Http' => 'http.md', 'Io' => 'async.md', 'Io\\Poll' => 'async.md',
    'Buffer' => 'http.md#buffer', 'Process' => 'superset.md#14-process-model--process',
    'Manticore\\Sapi' => 'http.md', 'Runtime' => 'modules.md', 'Ffi' => 'ffi.md',
];
$allPhpFn = [];
foreach ($phpFns as $fs) { foreach ($fs as $k => $_) { $allPhpFn[$k] = true; } }
$allPhpTy = [];
foreach ($phpTypes as $ts) { foreach ($ts as $k => $_) { $allPhpTy[$k] = true; } }

$nsNames = \array_unique(\array_merge(\array_keys($nsFns), \array_map(fn ($t) => $t['ns'], \array_filter($ourTypes, fn ($t) => $t['ns'] !== ''))));
\sort($nsNames);
$out[] = '### Namespaces';
$out[] = '';
$rtFns = 0; $rtTys = 0;
foreach ($nsNames as $ns) {
    $fs = \array_values(\array_filter($nsFns[$ns] ?? [], fn ($f) => !\str_contains($f, '\\__'))); \sort($fs);
    $ts = \array_values(\array_filter($ourTypes, fn ($t) => $t['ns'] === $ns));
    if ($ns === 'Runtime' || \str_starts_with($ns, 'Runtime\\')) { $rtFns += \count($fs); $rtTys += \count($ts); continue; }
    \usort($ts, fn ($a, $b) => \strcmp($a['name'], $b['name']));
    $link = $docLinks[$ns] ?? null;
    $out[] = '#### `' . $ns . '\\`' . ($link !== null ? ' — [' . $link . '](' . $link . ')' : '');
    $out[] = '';
    if ($fs !== []) { $out[] = 'Functions: ' . \implode(' ', \array_map(fn ($f) => '`' . $f . '`', $fs)); $out[] = ''; }
    if ($ts !== []) {
        $out[] = 'Types: ' . \implode(' ', \array_map(fn ($t) => '`' . $t['name'] . '`' . ($t['kind'] !== 'class' ? ' (' . $t['kind'] . ')' : ''), $ts));
        $out[] = '';
    }
}

$out[] = '#### `Runtime\\*` — internal';
$out[] = '';
$out[] = $rtFns . ' functions and ' . $rtTys . ' types under `Runtime\\Libc`, `Runtime\\Pcre`, `Runtime\\Openssl`,';
$out[] = '`Runtime\\Iconv`, `Runtime\\Crypto`, `Runtime\\Stdlib`: the libc / PCRE2 / OpenSSL bindings the';
$out[] = 'stdlib is written over (`src/Runtime/*.php`). Not user API; listed only by count.';
$out[] = '';

$extraFns = []; $internalFns = [];
foreach ($ours as $k => $tier) {
    if (isset($allPhpFn[$k]) || $tier === 'l') { continue; }
    if (\str_starts_with($k, '__') || \str_starts_with($k, 'manticore_') || \str_starts_with($k, 'mc_')) { $internalFns[] = $k; continue; }
    $extraFns[] = '`' . $spell[$k] . '`<sup>' . $tier . '</sup>';
}
\sort($extraFns); \sort($internalFns);
$extraTys = [];
foreach ($ourTypes as $k => $t) {
    if ($t['ns'] !== '' || isset($allPhpTy[$k])) { continue; }
    if (\str_starts_with($k, '__') || \str_starts_with($k, 'manticore')) { continue; }
    $extraTys[] = '`' . $t['name'] . '`' . ($t['kind'] !== 'class' ? ' (' . $t['kind'] . ')' : '');
}
\sort($extraTys);
$out[] = '### Global names that are not PHP\'s';
$out[] = '';
$out[] = 'Global functions and classes Manticore defines that the reference `php` does';
$out[] = 'not. Some are deliberate (`manticore.json`-era helpers, PHP names from';
$out[] = 'extensions this host has not loaded); anything else here is namespace';
$out[] = 'pollution and a candidate for a `__` prefix or a namespace.';
$out[] = '';
$out[] = 'Functions (' . \count($extraFns) . '): ' . \implode(' ', $extraFns);
$out[] = '';
$out[] = 'Types (' . \count($extraTys) . '): ' . \implode(' ', $extraTys);
$out[] = '';
$out[] = 'Internal helpers (`__*`, `manticore_*`, `mc_*`): ' . \count($internalFns) . ' — not user API.';
$out[] = '';

$out[] = '## Per extension';
$out[] = '';
foreach ($sections as $s) { $out[] = $s; }

$text = \implode("\n", $out) . "\n";
if ($toStdout) { echo $text; exit(0); }
\file_put_contents($root . '/docs/builtins.md', $text);
\fwrite(STDERR, 'docs/builtins.md: functions ' . $hitF . '/' . $totF . ', classes ' . $hitC . '/' . $totC . "\n");
