<?php
// getDefaultValue() of a plain function's parameter throws "Failed to retrieve the default value" (methods work)
// issue: #197
namespace N;
class K {}
function f(?K $b = null, string $c = 'x') {}
foreach ((new \ReflectionFunction('N\f'))->getParameters() as $p) {
    echo var_export($p->getDefaultValue(), true), "\n";
}
