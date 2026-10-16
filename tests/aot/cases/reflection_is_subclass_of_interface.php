<?php
// ReflectionClass::isSubclassOf is true for an implemented interface too, false for the class itself.
namespace N;

interface EnvVarProcessorInterface { public function getEnv(string $prefix, string $name, \Closure $getEnv): mixed; }
interface Other {}
class Base {}
final class EnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): mixed { return null; }
}
final class Child extends Base implements Other {}

function check(?string $class): void
{
    $r = new \ReflectionClass($class);
    echo $class, ': ',
        var_export($r->isSubclassOf(EnvVarProcessorInterface::class), true), ' ',
        var_export($r->isSubclassOf(Base::class), true), ' ',
        var_export($r->isSubclassOf(Other::class), true), ' ',
        var_export($r->isSubclassOf($class), true), ' ',
        var_export($r->implementsInterface(EnvVarProcessorInterface::class), true), "\n";
}
check(EnvVarProcessor::class);
check(Child::class);
$cls = (new \ReflectionClass(EnvVarProcessor::class))->getName();
$def = ['class' => $cls];
$r = new \ReflectionClass($def['class']);
var_dump($r->isSubclassOf(EnvVarProcessorInterface::class));
