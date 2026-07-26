<?php

// PHP's reserved attribute classes exist and reflect.

echo Attribute::TARGET_CLASS, "\n";
echo Attribute::TARGET_FUNCTION, "\n";
echo Attribute::TARGET_METHOD, "\n";
echo Attribute::TARGET_PROPERTY, "\n";
echo Attribute::TARGET_CLASS_CONSTANT, "\n";
echo Attribute::TARGET_PARAMETER, "\n";
echo Attribute::TARGET_CONSTANT, "\n";
echo Attribute::TARGET_ALL, "\n";
echo Attribute::IS_REPEATABLE, "\n";

$names = ['Attribute', 'AllowDynamicProperties', 'ReturnTypeWillChange',
          'SensitiveParameter', 'SensitiveParameterValue', 'Override',
          'Deprecated', 'NoDiscard', 'DelayedTargetValidation'];
foreach ($names as $n) {
    $r = new ReflectionClass($n);
    echo $r->getName(), " final=", $r->isFinal() ? 'y' : 'n', "\n";
}

$d = new Deprecated('use g() instead', '1.5');
echo $d->message, " / ", $d->since, "\n";
$d2 = new Deprecated();
var_dump($d2->message);
var_dump($d2->since);

$nd = new NoDiscard('because');
echo $nd->message, "\n";

$a = new Attribute();
echo $a->flags, "\n";
$a2 = new Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY);
echo $a2->flags, "\n";
