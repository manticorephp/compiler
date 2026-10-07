<?php
use Manticore\Ds\Map;

class B
{
    public static ?Map $m = null;
    public static string $mode = '';
    public function __construct(public string $tag) {}
    public function __destruct()
    {
        $m = self::$m;
        if (self::$mode === 'add') {
            for ($i = 0; $i < 64; $i++) { $m->set("{$this->tag}$i", $i); }
        } elseif (self::$mode === 'remove') {
            $m->remove('victim');
            $m->set('after', 1);
        }
    }
}

$m = new Map();
B::$m = $m;

$m->set('a', new B('u'));
$m->set('keep', 1);
B::$mode = 'add';
unset($m['a']);
echo count($m), ' ', $m->has('u63') ? 'y' : 'n', ' ', $m->get('u0'), "\n";

$m->set('b', new B('o'));
$m->set('b', 0);
echo count($m), ' ', $m->has('o63') ? 'y' : 'n', ' ', $m->get('b'), "\n";

$m->set('c', new B('r'));
$m->remove('c');
echo count($m), ' ', $m->has('r10') ? 'y' : 'n', "\n";

$m->clear();
echo count($m), "\n";
$m->set('z', new B('c'));
$m->set('w', 5);
$m->clear();
echo count($m), ' ', $m->has('c63') ? 'y' : 'n', ' ', $m->has('z') ? 'y' : 'n', "\n";
$n = 0;
foreach ($m as $k => $v) { $n += $v; }
echo $n, "\n";

$m->clear();
B::$mode = 'remove';
$m->set('victim', 1);
$m->set('trig', new B('t'));
$m->remove('trig');
echo count($m), ' ', $m->has('victim') ? 'y' : 'n', ' ', $m->get('after'), "\n";
