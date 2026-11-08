<?php
// get_object_vars($o) answers by the calling scope for another object too: a closure in
// UrlGenerator walked every private property of a request attribute's whole object graph.
class Base { public $pub = 1; protected $pro = 2; private $pri = 3; public function own(object $o): array { return get_object_vars($o); } public function me(): array { return get_object_vars($this); } }
class Child extends Base { public $cp = 4; protected $cq = 5; private $cr = 6; public function sub(object $o): array { return get_object_vars($o); } }
class Other { public function see(object $o): array { return get_object_vars($o); }
    public function viaClosure(object $o): array { $f = static function ($x) { return get_object_vars($x); }; return $f($o); } }
class Node_ { public $v = 1; private $next; public function __construct(?Node_ $n = null) { $this->next = $n ?? $this; } }
$c = new Child; $b = new Base; $o = new Other;
foreach ([$b->me(), $c->me(), $b->own(new Base), $b->own($c), $c->sub($b), $c->sub(new Child), $o->see($c), $o->viaClosure($c), get_object_vars($c)] as $r) { echo json_encode($r), "\n"; }
$d = new stdClass; $d->x = 1; echo json_encode($o->see($d)), "\n";
function walk(array $extra) {
    array_walk_recursive($extra, $caster = static function (&$v) use (&$caster) {
        if (\is_object($v)) { if ($vars = get_object_vars($v)) { array_walk_recursive($vars, $caster); $v = $vars; } }
    });
    return $extra;
}
class U { public function go(array $extra) { array_walk_recursive($extra, $caster = static function (&$v) use (&$caster) {
    if (\is_object($v)) { if ($vars = get_object_vars($v)) { array_walk_recursive($vars, $caster); $v = $vars; } } }); return $extra; } }
echo json_encode((new U)->go(['n' => new Node_, 'c' => new Child])), json_encode(walk(['n' => new Node_])), "\n";
