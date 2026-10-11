<?php
namespace Acme\Cfg;

final class Configurator
{
    public array $log = [];
    public function set(string $k, string $v): static { $this->log[] = "$k=$v"; return $this; }
}
