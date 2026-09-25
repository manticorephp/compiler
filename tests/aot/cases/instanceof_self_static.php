<?php
// `instanceof self|static|parent` names the class like `new self` does; the
// narrowed store `$c->root = …` hit no slot when it compared against "self".
class Base { public function make(): Base { return new static(); } }
final class Kid extends Base
{
    private string $root = 'own';
    private bool $flag = false;
    public function child(): Base
    {
        $c = parent::make();
        if ($c instanceof self) {
            $c->flag = true;
            $c->root = $this->root;
        }
        return $c;
    }
    public function setRoot(string $r): void { $this->root = $r; }
    public function show(): string { return $this->root . ($this->flag ? '+' : '-'); }
}
$k = new Kid();
$k->setRoot('parent-root');
$c = $k->child();
echo $c->show(), "\n";
class P1 { public function isMe(object $o): bool { return $o instanceof static; } public function isSelf(object $o): bool { return $o instanceof self; } }
class P2 extends P1 { public function isParent(object $o): bool { return $o instanceof parent; } }
$a = new P1(); $b = new P2();
var_dump($b->isMe($a), $b->isMe($b), $a->isMe($b), $b->isSelf($a), $b->isParent($a), (new P1())->isSelf(new \stdClass()));
