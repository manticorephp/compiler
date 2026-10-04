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
