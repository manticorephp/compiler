<?php
// A superglobal written on both sides of the module boundary: the library's
// stores and the application's land in ONE slot of one representation.
$_GET = ['app' => 1];
\Acme\Gpc::set('lib', 'x');
echo \Acme\Gpc::keys(), "\n";
for ($i = 0; $i < 3; $i++) {
    \Acme\Gpc::reset($i);
    $_GET['app' . $i] = \str_repeat('a', 64);
    echo implode(',', array_keys($_GET)), "\n";
    $_GET = ['fresh' => $i];
}
echo \Acme\Gpc::keys(), ' ', $_GET['fresh'], "\n";

// Every store releases what it overwrites, on both sides of the boundary.
for ($i = 0; $i < 2000; $i++) { \Acme\Gpc::reset($i); $_GET = ['fresh' => $i]; }
$b = memory_get_usage();
for ($i = 0; $i < 50000; $i++) {
    \Acme\Gpc::reset($i);
    $_GET['app'] = \str_repeat('a', 200) . $i;
    $_GET = ['fresh' => \str_repeat('f', 200) . $i];
}
$g = memory_get_usage() - $b;
echo $g < 2 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
