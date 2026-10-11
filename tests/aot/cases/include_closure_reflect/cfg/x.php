<?php
namespace N;

return static function (Configurator $c, string $env = 'dev', ?int $n = null): void {
    echo 'run ', $env, "\n";
};
