<?php
// mysqli surface audit.
//   php -d xdebug.mode=off tools/mysqli_surface_audit.php --dump > tests/mysqli_net/surface.json
//   php -n tools/mysqli_surface_audit.php --check <prelude files...>
// --check: reflects the live runtime (restricted to the golden's names) and diffs it against
// tests/mysqli_net/surface.json; exit 1 with a diff on any mismatch.
// Loader used: with no files, or when mysqli is not loaded, the prelude files are require'd directly
// under Zend. FALLBACK (the one in force on this host): `php -n` does NOT unload mysqli here
// (statically built in), so the prelude cannot be declared under Zend. Then use the compiled probe:
// compile this file with bin/manticore (prelude included), run `<probe> --live > live.json`,
// then `php tools/mysqli_surface_audit.php --diff live.json`.
error_reporting(E_ALL & ~E_DEPRECATED);

function typeStr(?ReflectionType $t): ?string
{
    return $t === null ? null : (string) $t;
}

function describeFunction(ReflectionFunctionAbstract $f): array
{
    $params = [];
    foreach ($f->getParameters() as $p) {
        $d = null;
        if ($p->isDefaultValueAvailable()) {
            $d = $p->isDefaultValueConstant() ? 'const:' . $p->getDefaultValueConstantName() : var_export($p->getDefaultValue(), true);
        }
        $params[] = [
            'name' => $p->getName(),
            'type' => typeStr($p->getType()),
            'default' => $d,
            'byRef' => $p->isPassedByReference(),
            'variadic' => $p->isVariadic(),
        ];
    }
    return ['params' => $params, 'return' => typeStr($f->getReturnType())];
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
            'readonly' => $prop->isReadOnly(),
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

/** Same shape as dumpSurface, but restricted to the names the golden lists, from the live runtime. */
function liveSurface(array $golden): array
{
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
if ($mode === '--live') {
    $golden = json_decode(file_get_contents(__DIR__ . '/../tests/mysqli_net/surface.json'), true);
    echo json_encode(liveSurface($golden), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
if ($mode === '--diff') {
    $golden = json_decode(file_get_contents(__DIR__ . '/../tests/mysqli_net/surface.json'), true);
    $out = [];
    diffTree($golden, json_decode(file_get_contents($argv[2]), true), '', $out);
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
    diffTree($golden, $live, '', $out);
    if ($out) {
        echo implode("\n", $out), "\n", count($out), " difference(s)\n";
        exit(1);
    }
    echo "mysqli surface OK\n";
    exit(0);
}
fwrite(STDERR, "usage: --dump | --check <prelude files...>\n");
exit(2);
