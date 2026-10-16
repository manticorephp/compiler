<?php
// The IS_* modifier/filter constants of the reflection classes, and getMethods()/getProperties() filtering by them
// (symfony's AttributeAutoconfigurationPass calls getMethods(ReflectionMethod::IS_PUBLIC)).
echo ReflectionMethod::IS_STATIC, ' ', ReflectionMethod::IS_PUBLIC, ' ', ReflectionMethod::IS_PROTECTED, ' ', ReflectionMethod::IS_PRIVATE, ' ',
    ReflectionMethod::IS_ABSTRACT, ' ', ReflectionMethod::IS_FINAL, "\n";
echo ReflectionProperty::IS_STATIC, ' ', ReflectionProperty::IS_PUBLIC, ' ', ReflectionProperty::IS_PROTECTED, ' ', ReflectionProperty::IS_PRIVATE, ' ',
    ReflectionProperty::IS_READONLY, ' ', ReflectionProperty::IS_ABSTRACT, ' ', ReflectionProperty::IS_FINAL, "\n";
echo ReflectionClass::IS_IMPLICIT_ABSTRACT, ' ', ReflectionClass::IS_EXPLICIT_ABSTRACT, ' ', ReflectionClass::IS_FINAL, "\n";
echo ReflectionClassConstant::IS_PUBLIC, ' ', ReflectionClassConstant::IS_PROTECTED, ' ', ReflectionClassConstant::IS_PRIVATE, ' ', ReflectionClassConstant::IS_FINAL, "\n";

class Svc
{
    public int $p1 = 1;
    protected int $p2 = 2;
    private int $p3 = 3;
    public static int $p4 = 4;

    public function a() {}
    protected function b() {}
    private function c() {}
    public static function d() {}
}
$r = new ReflectionClass(Svc::class);
$names = fn(array $l) => implode(',', array_map(fn($x) => $x->getName(), $l));
echo $names($r->getMethods(ReflectionMethod::IS_PUBLIC)), ' | ', $names($r->getMethods(ReflectionMethod::IS_PROTECTED | ReflectionMethod::IS_PRIVATE)), ' | ',
    $names($r->getMethods(ReflectionMethod::IS_STATIC)), ' | ', count($r->getMethods()), "\n";
echo $names($r->getProperties(ReflectionProperty::IS_PUBLIC)), ' | ', $names($r->getProperties(ReflectionProperty::IS_PRIVATE)), ' | ',
    $names($r->getProperties(ReflectionProperty::IS_STATIC)), ' | ', count($r->getProperties()), "\n";
