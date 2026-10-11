<?php
namespace App;

function plain(int $a, string ...$r): ?string { return null; }

class K {
    public function __construct(public int $x = 1) {}
    public function m(int $a, $b = 2): int { return 1; }
}

function info(\ReflectionFunctionAbstract $f): string {
    $p = $f->getParameters();
    $rt = $f->getReturnType();
    return $f->getName() . ' ' . count($p) . ' ' . $f->getNumberOfRequiredParameters()
        . ' ' . ($rt === null ? '-' : (string) $rt) . ' ' . count($f->getAttributes())
        . ' ' . ($f->isVariadic() ? 'v' : 'n') . ($f->isUserDefined() ? 'u' : 'i');
}

$m = new \ReflectionMethod(K::class, 'm');
$c = (new \ReflectionClass(K::class))->getConstructor();
$f = new \ReflectionFunction('App\plain');
$cl = new \ReflectionFunction(fn(int $z): int => $z);
$rc = new \ReflectionClass(K::class);

foreach ([$m, $c, $f, $cl, $rc] as $o) {
    echo get_class($o), ' ', var_export($o instanceof \ReflectionFunctionAbstract, true), "\n";
}
echo info($m), "\n", info($c), "\n", info($f), "\n", substr(info($cl), -14), "\n";

$calls = [[$c, ['a']], [$m, []], ['m', []], [$f, []]];
foreach ($calls as $call) {
    [$method, $arguments] = $call;
    if ($method instanceof \ReflectionFunctionAbstract) {
        $r = $method;
    } else {
        $r = new \ReflectionMethod(K::class, $method);
    }
    echo $r->getName(), ' ', count($r->getParameters()), "\n";
}
