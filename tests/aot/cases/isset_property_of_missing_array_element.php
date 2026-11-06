<?php
class FM { public ?string $declared = null; public function __construct(public string $n) {} }
class MD { public array $fieldMappings = []; public array $associationMappings = []; public array $embeddedClasses = []; }
$m = new MD; $m->fieldMappings['a'] = new FM('a'); $m->fieldMappings['b'] = new FM('b'); $m->fieldMappings['b']->declared = 'X';
foreach (['a', 'b', 'zz'] as $name) {
    var_dump(isset($m->fieldMappings[$name]->declared));
    var_dump(isset($m->fieldMappings[$name]->declared) && $m->fieldMappings[$name]->declared === 'X');
    var_dump(isset($m->associationMappings[$name]->declared));
    var_dump(isset($m->embeddedClasses[$name]->declared) && $m->embeddedClasses[$name]->declared === 'X');
}
function t(MD $metadata, \ReflectionProperty $property, string $declaringClass): bool {
    return isset($metadata->fieldMappings[$property->name]->declared) && $metadata->fieldMappings[$property->name]->declared === $declaringClass;
}
class Q { public $a; public $zz; }
var_dump(t($m, new ReflectionProperty('Q', 'a'), 'X'), t($m, new ReflectionProperty('Q', 'zz'), 'X'));
