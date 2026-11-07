<?php
// An untyped value stored into a property documented `\Closure|null` takes its own count on the closure env
class P {
    /** @var \Closure|null */
    protected $u;
    public function set($c) { $this->u = $c; }
    public function pass($app) { return $app->take($this->u); }
}
class C { public function take($cb) { return is_callable($cb) ? $cb() : 'no'; } }
function mk($web) { return function () use ($web) { return "called:$web"; }; }
$p = new P;
$p->set(mk('/Users/taras/routes/web.php'));
for ($i = 0; $i < 3; $i++) { $q = str_repeat('x', 64) . $i; }
echo $p->pass(new C), "\n";
