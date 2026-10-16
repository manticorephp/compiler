<?php
// A closure returned by an included file, reflected through $cb(...):
// symfony's PhpFileLoader::executeCallback shape.
namespace N;

class Configurator {}

function load(string $name): void {
    $cb = include __DIR__ . '/cfg/' . $name;
    $cb = $cb(...);
    $r = new \ReflectionFunction($cb);
    echo str_starts_with($r->getName(), '{closure') ? 'closure' : 'other', ' ', count($r->getAttributes()), "\n";
    foreach ($r->getParameters() as $p) {
        $t = $p->getType();
        if (!$t instanceof \ReflectionNamedType) { throw new \Exception("t"); }
        echo $p->getName(), ' ', $t->getName(), ' ', var_export($t->isBuiltin(), true), ' ',
            var_export($t->allowsNull(), true), ' ',
            $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-', "\n";
    }
    echo $r->getReturnType()?->getName(), "\n";
    $cb(new Configurator(), 'prod');
}
load('x.php');
