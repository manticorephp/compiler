<?php
// class_implements() and ReflectionClass::getInterfaceNames() of an interface omit the interfaces it extends.
// issue: #242

interface I {}
interface J extends I {}

echo json_encode(class_implements('J')), "\n";
echo json_encode((new ReflectionClass('J'))->getInterfaceNames()), "\n";
var_dump((new ReflectionClass('J'))->implementsInterface('I'));
