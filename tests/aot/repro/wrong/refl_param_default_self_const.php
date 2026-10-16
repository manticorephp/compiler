<?php
// ReflectionParameter::getDefaultValue() of a default written `self::X` fails with 'Class "" not found' (evaluated outside the declaring class)
// issue: #288
class P {
    const X = 3;
    public function g($x = [1, 2], $y = self::X) {}
}
foreach ((new ReflectionMethod('P', 'g'))->getParameters() as $p) {
    echo $p->getName(), ' = ', json_encode($p->getDefaultValue()), "\n";
}
