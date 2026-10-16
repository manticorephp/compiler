<?php
// ReflectionProperty::setValue() on a property of a prelude class (Exception::$message, ::$code) throws "Cannot write property"
// issue: #289
$e = new RuntimeException('before');
$p = new ReflectionProperty('Exception', 'message');
$p->setValue($e, 'after');
echo $e->getMessage(), "\n";
(new ReflectionClass('Exception'))->getProperty('code')->setValue($e, 42);
echo $e->getCode(), "\n";
