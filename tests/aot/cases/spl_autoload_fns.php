<?php
echo spl_autoload_extensions(), "\n";
echo spl_autoload_extensions('.php,.inc'), "\n";
echo spl_autoload_extensions(), "\n";
$c = spl_classes();
echo count($c), " ", $c['ArrayIterator'], " ", isset($c['SplSubject']) ? 'y' : 'n', " ", array_key_first($c), " ", array_key_last($c), "\n";
$seen = [];
spl_autoload_register(function (string $cls) use (&$seen) { $seen[] = $cls; });
spl_autoload_call('Nope\Missing');
spl_autoload('Other');
echo implode(",", $seen), "\n";
var_dump(class_exists('Nope\Missing'));
