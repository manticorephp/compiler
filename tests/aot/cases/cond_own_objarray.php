<?php

// Object / array conditionals: the destructor ORDER and COUNT is the proof — a
// missing retain destructs early (or crashes), a double retain never destructs.

class Node_
{
    public function __construct(public string $name) {}
    public function __destruct() { echo 'dtor ', $this->name, "\n"; }
}

function churnObjs(int $n): void
{
    for ($i = 0; $i < $n; $i = $i + 1) { $t = new Node_('t' . $i); unset($t); }
}

// A borrowed obj arm survives the source local going out of scope.
function keepOne(bool $c): Node_
{
    $a = new Node_('a');
    return $c ? new Node_('fresh') : $a;
}

$k = keepOne(false);
echo 'have ', $k->name, "\n";
unset($k);
echo "--1--\n";

// Property arm + element arm into a local.
class Bag
{
    public Node_ $one;
    /** @var Node_[] */
    public array $list = [];
    public function __construct() { $this->one = new Node_('prop'); $this->list[] = new Node_('elem'); }
}
$bag = new Bag();
$sel = true ? $bag->one : $bag->list[0];
$sel2 = false ? $bag->one : $bag->list[0];
echo $sel->name, ' ', $sel2->name, "\n";
unset($sel);
unset($sel2);
echo 'bag alive: ', $bag->one->name, ' ', $bag->list[0]->name, "\n";
unset($bag);
echo "--2--\n";

// Two different classes → a static union result.
class B_ { public function who(): string { return 'B'; } }
class C_ { public function who(): string { return 'C'; } }
function chooseCls(bool $c): string
{
    $o = $c ? new B_() : new C_();
    return $o->who();
}
echo chooseCls(true), chooseCls(false), "\n";

// Arrays: a borrowed vec arm must not be freed by its source's scope exit.
function pickList(bool $c): array
{
    $mine = ['a', 'b', 'c'];
    return $c ? ['x'] : $mine;
}
$l = pickList(false);
churnObjs(8);
echo \implode(',', $l), ' ', \count($l), "\n";

// An assoc arm through `??` and a property store.
class Cfg
{
    /** @var array<string,string> */
    public array $opts = [];
}
function optsOf(array $src): array
{
    $base = ['mode' => 'fast'];
    return \count($src) > 0 ? $src : $base;
}
$cfg = new Cfg();
$cfg->opts = optsOf([]);
churnObjs(8);
echo $cfg->opts['mode'], ' ', \count($cfg->opts), "\n";

// match over objects, plus a discarded conditional statement.
function nodeFor(int $i, Node_ $borrowed): Node_
{
    return match ($i) {
        0 => $borrowed,
        default => new Node_('made'),
    };
}
$src = new Node_('src');
$n0 = nodeFor(0, $src);
echo $n0->name, "\n";
unset($n0);
echo 'src alive: ', $src->name, "\n";
$n1 = nodeFor(1, $src);
echo $n1->name, "\n";
unset($n1);
unset($src);
echo "--3--\n";
