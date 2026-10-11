<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Acme\Cfg\Configurator;
use function Acme\Cfg\fn\service;

return static function (Configurator $c): void {
    $c->set('a', service('x'))->set('b', 'two');
};
