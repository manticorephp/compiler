<?php
// issue: #566
if (method_exists(Exception::class, 'getMessage')) {
    class CondBundle {}
} else {
    class CondBundle {}
}
$f = (new ReflectionClass('CondBundle'))->getFileName();
echo is_string($f) ? basename($f) : var_export($f, true), "\n";
