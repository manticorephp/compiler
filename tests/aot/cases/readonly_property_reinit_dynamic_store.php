<?php
class Ro { public readonly string $x; public function init(): void { $this->x = 'init'; } }
$o = (new ReflectionClass('Ro'))->newInstanceWithoutConstructor();
$set = \Closure::bind(function (string $k, string $v): void { $this->$k = $v; }, $o, 'Ro');
$set('x', 'a');
try { $o->init(); echo $o->x, "\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$set2 = \Closure::bind(function (): void { $this->x = 'b'; }, $o, 'Ro');
try { $set2(); echo $o->x, "\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo $o->x, "\n";
