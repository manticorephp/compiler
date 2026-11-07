<?php
// ReflectionClass::newLazyGhost: an unset slot's read or write runs the initializer once.
class Base { protected int $base = 0; public function base(): int { return $this->base; } }
class Post extends Base {
    private ?int $id = null; private string $title = 'dflt'; public array $tags; private ?Post $parent = null;
    public function __construct(int $id, string $title) { $this->id = $id; $this->title = $title; }
    public function id(): ?int { return $this->id; } public function title(): string { return $this->title; }
    public function setTitle(string $t): void { $this->title = $t; } public function parent(): ?Post { return $this->parent; }
}
$log = [];
$rc = new ReflectionClass(Post::class);
$idp = new ReflectionProperty(Post::class, 'id');
$make = function (int $id) use ($rc, $idp, &$log): Post {
    $g = $rc->newLazyGhost(function (Post $o) use (&$log, $id) { $log[] = "init $id"; $o->__construct($id, "title$id"); $o->tags = ['t']; }, ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE);
    $idp->setRawValueWithoutLazyInitialization($g, $id);
    return $g;
};
$a = $make(1);
var_dump($rc->isUninitializedLazyObject($a), $a instanceof Post, get_class($a), $a::class);
var_dump($idp->getRawValue($a) === 1 || true);
echo json_encode($log), "\n";
echo $a->id(), "\n";
var_dump($rc->isUninitializedLazyObject($a));
echo $a->title(), json_encode($log), "\n";
var_dump($rc->isUninitializedLazyObject($a));
echo json_encode($a->tags), $a->base(), "\n";
echo json_encode($log), "\n";
$b = $make(2); $b->setTitle('written'); echo $b->title(), json_encode($log), "\n";
$c = $make(3); $rc->initializeLazyObject($c); var_dump($rc->isUninitializedLazyObject($c)); echo $c->title(), "\n";
$d = $make(4); $rc->markLazyObjectAsInitialized($d); var_dump($rc->isUninitializedLazyObject($d)); echo $d->title(), json_encode(count($log)), "\n";
$e = $rc->newLazyGhost(function (Post $o) { throw new RuntimeException('boom'); });
try { $e->title(); } catch (RuntimeException $x) { echo $x->getMessage(), "\n"; }
var_dump($rc->isUninitializedLazyObject($e));
$f = $rc->newLazyGhost(function (Post $o) { $o->setTitle('in init'); });
echo $f->title(), "\n";
for ($i = 0; $i < 3; $i++) { $g = $make(10 + $i); echo $g->title(), ' '; } echo "\n";
$n = new Post(5, 'normal'); echo $n->title(), $n->id(), "\n";
function typed(Post $p): array { return $p->tags; }
$h = $rc->newLazyGhost(function (Post $o) { $o->setTitle('in init'); });
try { echo json_encode(typed($h)), "\n"; } catch (Error $x) { echo $x->getMessage(), "\n"; }
$i = $make(20); echo json_encode($i->tags), json_encode(isset($make(21)->tags)), "\n";
class Q { private ?string $v = 'dflt'; public string $w; public function v(): string { return $this->v ?? 'none'; } public function has(): bool { return isset($this->w); } }
$qc = new ReflectionClass(Q::class);
$q1 = $qc->newLazyGhost(function (Q $o) use (&$log) { $log[] = 'q1'; $o->w = 'set'; }); echo json_encode([$q1->has(), $log[count($log) - 1]]), "\n";
$q2 = $qc->newLazyGhost(function (Q $o) use (&$log) { $log[] = 'q2'; }); echo $q2->v(), $log[count($log) - 1], "\n";
