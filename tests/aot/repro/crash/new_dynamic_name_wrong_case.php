<?php
// `new $name` answers null instead of the class when the runtime name differs in case, and instead of throwing when no class has that name.
// issue: #580
class Zebra { public function name() { return 'zebra'; } }
foreach (['zebra', 'ZEBRA'] as $name) {
    $o = new $name();
    echo get_class($o), ' ', $o->name(), "\n";
}
try {
    $name = 'NoSuchClass';
    $o = new $name();
    echo get_class($o), "\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
