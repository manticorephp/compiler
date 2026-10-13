<?php
namespace N;
class K {}
function f(?K $b = null, string $c = 'x') {}
foreach ((new \ReflectionFunction('N\f'))->getParameters() as $p) {
    echo var_export($p->getDefaultValue(), true), "\n";
}
