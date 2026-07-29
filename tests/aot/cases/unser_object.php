<?php

class Plain
{
    public int $a = 1;
    public string $s = 'txt';
}

class Vis
{
    public int $a = 1;
    protected int $b = 2;
    private int $c = 3;

    public function show(): string
    {
        return $this->a . '/' . $this->b . '/' . $this->c;
    }
}

class Sub extends Vis
{
    public int $d = 4;
}

class Ctor
{
    public int $v;

    public function __construct(int $v = 0)
    {
        // unserialize must NOT run this — a rebuilt object takes the stream's
        // values, and a side effect here would fire on every read.
        echo "CTOR\n";
        $this->v = $v * 100;
    }
}

class RO
{
    public function __construct(public readonly int $x = 0)
    {
    }
}

class Typed
{
    public ?Plain $inner = null;
    public array $list = [];
    public float $f = 0.0;
    public bool $flag = false;
}

$p = unserialize(serialize(new Plain()));
var_dump(get_class($p), $p->a, $p->s);

$v = unserialize(serialize(new Vis()));
echo $v->show(), "\n";

$sub = unserialize(serialize(new Sub()));
echo $sub->show(), ' ', $sub->d, "\n";

$c = new Ctor(2);
$c2 = unserialize(serialize($c));
var_dump($c2->v);

$r = unserialize(serialize(new RO(42)));
var_dump($r->x);

$t = new Typed();
$t->inner = new Plain();
$t->list = [1, 'two', [3]];
$t->f = 2.5;
$t->flag = true;
$t2 = unserialize(serialize($t));
var_dump(get_class($t2->inner), $t2->inner->s, $t2->list, $t2->f, $t2->flag);

$o = new stdClass();
$o->k = 5;
$o->t = 'z';
$o2 = unserialize(serialize($o));
var_dump(get_class($o2), $o2->k, $o2->t);

var_dump(unserialize(serialize([new Plain(), 'k' => new Vis()]))['k']->show());
