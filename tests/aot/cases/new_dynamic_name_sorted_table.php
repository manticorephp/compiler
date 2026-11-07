<?php
// `new $name`, `$o->$m()` and `$f()` resolve through tables sorted lower-cased: names that
// sort differently with and without case folding, `_` against letters, namespaces.
namespace App\Zeta { class Leaf { public function __construct(public int $v = 1) {} public function name() { return 'leaf' . $this->v; } } }
namespace {
class Zebra { public function name() { return 'zebra'; } }
class apple { public function name() { return 'apple'; } }
class Foo_Bar { public function name() { return 'foo_bar'; } }
class FooBar { public function name() { return 'foobar'; } }
class FOOBAZ { public function __construct(public string $tag = 'd') {} public function name() { return 'foobaz' . $this->tag; } }
class Row { public $title; public $body; public $Mixed; }
class Multi {
    public function Zed() { return 'Zed'; }
    public function alpha() { return 'alpha'; }
    public function Beta_x() { return 'Beta_x'; }
    public function BetaX() { return 'BetaX'; }
    public function mid() { return 'mid'; }
}
function Zfn() { return 'Zfn'; }
function afn() { return 'afn'; }
function B_fn() { return 'B_fn'; }
function Bfn() { return 'Bfn'; }
function mfn() { return 'mfn'; }

foreach (['Zebra', 'apple', 'Foo_Bar', 'FooBar', 'FOOBAZ', 'App\Zeta\Leaf'] as $name) {
    $o = new $name();
    echo $name, ' => ', get_class($o), ' ', $o->name(), "\n";
}
$args = ['x'];
$name = 'FOOBAZ';
$o = new $name(...$args);
echo $o->name(), "\n";
$o = new Multi();
foreach (['Zed', 'alpha', 'Beta_x', 'BetaX', 'mid'] as $m) { echo $o->$m(), "\n"; }
foreach (['Zfn', 'zfn', 'afn', 'AFN', 'B_fn', 'b_FN', 'Bfn', 'bfn', 'mfn'] as $f) { echo $f(), "\n"; }
$pdo = new PDO('sqlite::memory:');
$r = $pdo->query("select 'a' as title, 'b' as body, 'c' as Mixed")->fetchAll(PDO::FETCH_CLASS, 'Row');
echo get_class($r[0]), ' ', $r[0]->title, $r[0]->body, $r[0]->Mixed, "\n";
}
