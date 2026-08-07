<?php
// @epic: reflection-tier4
// @why: doctrine hydrates entities without running the constructor and writes
//       private properties directly; var-exporter and the lazy-proxy generator
//       do the same. None of newInstanceWithoutConstructor/setAccessible/
//       getClosure exist in prelude/reflection.php.

final class CapEntity
{
    private string $title = 'unset';
    public function __construct(string $t) { $this->title = $t . '!'; }
    public function title(): string { return $this->title; }
}

$rc = new ReflectionClass(CapEntity::class);
$obj = $rc->newInstanceWithoutConstructor();
var_dump($obj->title());

$p = $rc->getProperty('title');
$p->setValue($obj, 'hydrated');
var_dump($obj->title());
var_dump($p->getValue($obj));
