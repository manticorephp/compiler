<?php

spl_autoload_register(function (string $cls): void {
    $f = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $cls) . '.php';
    if (is_file($f)) { require $f; }
});
