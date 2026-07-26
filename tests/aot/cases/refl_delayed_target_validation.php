<?php

// #[\DelayedTargetValidation] moves a reserved attribute's target check from
// compile time to ReflectionAttribute::newInstance().

#[\Override]
#[\DelayedTargetValidation]
class Delayed {}

$attrs = (new ReflectionClass('Delayed'))->getAttributes();
foreach ($attrs as $a) {
    echo $a->getName(), " target=", $a->getTarget(), " repeated=",
         $a->isRepeated() ? 'y' : 'n', "\n";
}
try {
    $attrs[0]->newInstance();
    echo "no error\n";
} catch (Error $e) {
    echo "E: ", $e->getMessage(), "\n";
}
$d = $attrs[1]->newInstance();
echo get_class($d), "\n";
