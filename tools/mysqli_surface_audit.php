<?php
// mysqli surface audit.
//   php -d xdebug.mode=off tools/mysqli_surface_audit.php --dump > tests/mysqli_net/surface.json
//   php -n tools/mysqli_surface_audit.php --check <prelude files...>
// --check: reflects the live runtime (restricted to the golden's names) and diffs it against
// tests/mysqli_net/surface.json; exit 1 with a diff on any mismatch.
// Loader used: with no files, or when mysqli is not loaded, the prelude files are require'd directly
// under Zend. FALLBACK (the one in force on this host): `php -n` does NOT unload mysqli here
// (statically built in), so the prelude cannot be declared under Zend. Then use the compiled probe:
// emit a probe source (`php tools/mysqli_surface_audit.php --emit-probe > probe.php`), compile THAT with bin/manticore
// (prelude included), run `<probe> --live > live.json`,
// then `php tools/mysqli_surface_audit.php --diff live.json`.
// Comparison keys: the live side may only use Reflection methods present in prelude/reflection.php, so
// the Zend-only data `tentative` (return came from a tentative type) and `readonly` (no
// ReflectionProperty::isReadOnly there) are dumped but stripped from BOTH sides before diffing.
// Defaults are compared as the evaluated value (var_export), not the constant name; types go through
// one normaliser (typeStr: `?T` => `T|null`, union members sorted, null last).
error_reporting(E_ALL & ~E_DEPRECATED);
// only the Zend side (--dump) can ask ReflectionProperty::isReadOnly (see #434); the key is stripped before diffing anyway
$dumping = ($argv[1] ?? '') === '--dump';
// --emit-probe fills this with the golden's names as literals: a compiled binary resolves a dynamic defined()/constant()/
// ReflectionFunction name only when the name also occurs as a string literal in the program.
$literalNames = []; // @@NAMES@@

function typeStr(?ReflectionType $t): ?string
{
    if ($t === null) {
        return null;
    }
    $s = (string) $t;
    if ($s[0] === '?') {
        $s = substr($s, 1) . '|null';
    }
    if (str_contains($s, '&')) {
        return $s;
    }
    $parts = explode('|', $s);
    $nul = in_array('null', $parts, true);
    $parts = array_values(array_filter($parts, fn($p) => $p !== 'null'));
    sort($parts);
    if ($nul) {
        $parts[] = 'null';
    }
    return implode('|', $parts);
}

function describeFunction(ReflectionFunctionAbstract $f): array
{
    $params = [];
    foreach ($f->getParameters() as $p) {
        $d = null;
        if ($p->isDefaultValueAvailable()) {
            $d = var_export($p->getDefaultValue(), true);
        }
        $params[] = [
            'name' => $p->getName(),
            'type' => typeStr($p->getType()),
            'default' => $d,
            'byRef' => $p->isPassedByReference(),
            'variadic' => $p->isVariadic(),
        ];
    }
    $ret = $f->getReturnType();
    $tentative = false;
    if ($ret === null && method_exists($f, 'getTentativeReturnType')) {
        $ret = $f->getTentativeReturnType();
        $tentative = $ret !== null;
    }
    return ['params' => $params, 'return' => typeStr($ret), 'tentative' => $tentative];
}

function describeClass(ReflectionClass $c): array
{
    $m = [];
    foreach ($c->getMethods() as $meth) {
        if ($meth->getDeclaringClass()->getName() !== $c->getName()) {
            continue;
        }
        $m[$meth->getName()] = describeFunction($meth) + [
            'static' => $meth->isStatic(),
            'visibility' => $meth->isPublic() ? 'public' : ($meth->isProtected() ? 'protected' : 'private'),
            'final' => $meth->isFinal(),
            'abstract' => $meth->isAbstract(),
        ];
    }
    ksort($m);
    $p = [];
    foreach ($c->getProperties() as $prop) {
        if ($prop->getDeclaringClass()->getName() !== $c->getName()) {
            continue;
        }
        $p[$prop->getName()] = [
            'type' => typeStr($prop->getType()),
            'readonly' => $GLOBALS['dumping'] ? $prop->isReadOnly() : null,
            'static' => $prop->isStatic(),
            'visibility' => $prop->isPublic() ? 'public' : ($prop->isProtected() ? 'protected' : 'private'),
        ];
    }
    ksort($p);
    $k = [];
    foreach ($c->getReflectionConstants() as $rc) {
        if ($rc->getDeclaringClass()->getName() === $c->getName()) {
            $k[$rc->getName()] = $rc->getValue();
        }
    }
    ksort($k);
    $i = $c->getInterfaceNames();
    sort($i);
    $parent = $c->getParentClass();
    return [
        'parent' => $parent ? $parent->getName() : null,
        'interfaces' => $i,
        'final' => $c->isFinal(),
        'methods' => $m,
        'properties' => $p,
        'constants' => $k,
    ];
}

function dumpSurface(): array
{
    $ext = new ReflectionExtension('mysqli');
    $classes = [];
    foreach ($ext->getClasses() as $name => $c) {
        $classes[$name] = describeClass($c);
    }
    ksort($classes);
    $functions = [];
    foreach ($ext->getFunctions() as $name => $f) {
        $functions[$name] = describeFunction($f);
    }
    ksort($functions);
    $consts = $ext->getConstants();
    ksort($consts);
    return ['classes' => $classes, 'functions' => $functions, 'constants' => $consts];
}

/**
 * Same shape as dumpSurface, but restricted to the names the golden lists, from the live runtime.
 * @param array<string,mixed> $golden
 * @return array<string,mixed>
 */
function liveSurface(array $golden): array
{
    $gate = MYSQLI_ASSOC; // a code mention of mysqli* is what links the prelude into the compiled probe
    $lit = $GLOBALS['literalNames'];
    if ($lit !== []) {
        $golden['functions'] = array_flip($lit['functions']);
        $golden['constants'] = array_flip($lit['constants']);
    }
    $classes = [];
    foreach (array_keys($golden['classes']) as $n) {
        if (class_exists($n) || interface_exists($n)) {
            $classes[$n] = describeClass(new ReflectionClass($n));
        }
    }
    $functions = [];
    foreach (array_keys($golden['functions']) as $n) {
        if (function_exists($n)) {
            $functions[$n] = describeFunction(new ReflectionFunction($n));
        }
    }
    $consts = [];
    foreach (array_keys($golden['constants']) as $n) {
        if (defined($n)) {
            $consts[$n] = constant($n);
        }
    }
    return ['classes' => $classes, 'functions' => $functions, 'constants' => $consts];
}

/** Drop the Zend-only keys (see header) from a surface tree. */
function strip(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    unset($v['tentative'], $v['readonly']);
    foreach ($v as $k => $x) {
        $v[$k] = strip($x);
    }
    return $v;
}

function diffTree(mixed $want, mixed $got, string $path, array &$out): void
{
    if (is_array($want) && is_array($got)) {
        foreach ($want as $k => $v) {
            if (!array_key_exists($k, $got)) {
                $out[] = "missing  $path/$k";
            } else {
                diffTree($v, $got[$k], "$path/$k", $out);
            }
        }
        foreach ($got as $k => $_) {
            if (!array_key_exists($k, $want)) {
                $out[] = "extra    $path/$k";
            }
        }
    } elseif ($want !== $got) {
        $out[] = "differs  $path: want " . json_encode($want) . ' got ' . json_encode($got);
    }
}

$mode = $argv[1] ?? '';
if ($mode === '--dump') {
    echo json_encode(dumpSurface(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
if ($mode === '--emit-probe') {
    $golden = json_decode(file_get_contents(__DIR__ . '/../tests/mysqli_net/surface.json'), true);
    $list = fn(array $m): string => '[' . implode(', ', array_map(fn($n) => "'$n'", array_keys($m))) . ']';
    $lit = "['functions' => " . $list($golden['functions']) . ", 'constants' => " . $list($golden['constants']) . ']';
    echo str_replace('$literalNames = []; ' . '// @@NAMES@@', '$literalNames = ' . $lit . ';', file_get_contents(__FILE__));
    exit(0);
}
if ($mode === '--live') {
    $golden = json_decode(file_get_contents(__DIR__ . '/../tests/mysqli_net/surface.json'), true);
    echo json_encode(liveSurface($golden), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
if ($mode === '--diff') {
    $golden = json_decode(file_get_contents(__DIR__ . '/../tests/mysqli_net/surface.json'), true);
    $out = [];
    diffTree(strip($golden), strip(json_decode(file_get_contents($argv[2]), true)), '', $out);
    if ($out) {
        echo implode("\n", $out), "\n", count($out), " difference(s)\n";
        exit(1);
    }
    echo "mysqli surface OK\n";
    exit(0);
}
if ($mode === '--check') {
    if (count($argv) > 2 && extension_loaded('mysqli')) {
        fwrite(STDERR, "mysqli is loaded in this php; use the compiled probe (--live) + --diff\n");
        exit(2);
    }
    foreach (array_slice($argv, 2) as $file) {
        require $file;
    }
    $golden = json_decode(file_get_contents(__DIR__ . '/../tests/mysqli_net/surface.json'), true);
    $out = [];
    // the built-in extension is not the prelude: with it loaded and no files, nothing is declared
    $live = extension_loaded('mysqli') ? ['classes' => [], 'functions' => [], 'constants' => []] : liveSurface($golden);
    diffTree(strip($golden), strip($live), '', $out);
    if ($out) {
        echo implode("\n", $out), "\n", count($out), " difference(s)\n";
        exit(1);
    }
    echo "mysqli surface OK\n";
    exit(0);
}
fwrite(STDERR, "usage: --dump | --check <prelude files...>\n");
exit(2);
