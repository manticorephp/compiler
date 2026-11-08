<?php
foreach (['Closure', 'Generator', 'closure', 'Nope'] as $n) {
    var_dump(class_exists($n), interface_exists($n));
}
var_dump(class_exists('Closure'), class_exists('Generator'));
