<?php
// In a program that makes lazy ghosts, an UNTYPED property of an ordinary object still reads as null before any write.
class App { protected $appPath; protected $base = '/b'; public $n;
  public function path(string $p = ''): string { return ($this->appPath ?: $this->base . '/app') . $p; }
  public function use(string $p): static { $this->appPath = $p; return $this; } }
class Svc { public int $x = 0; public $y; public function __construct() { $this->x = 7; } }
$a = new App();
echo $a->path('/x'), "\n";
var_dump($a->n);
echo $a->use('/z')->path('/y'), "\n";
$r = new ReflectionClass(Svc::class);
$g = $r->newLazyGhost(function (Svc $s) { $s->__construct(); $s->y = 'init'; });
echo $g->x, ' ', $g->y, "\n";
