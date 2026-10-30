<?php
require __DIR__ . '/A.php';
require __DIR__ . '/B.php';
echo \App\A::make()->name(), \App\B::make()->name(), "\n";
