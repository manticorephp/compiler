<?php
class D {
    public $self;
    public function __construct(public string $n) {}
    public function __destruct() { echo "dtor {$this->n}\n"; }
}
class H {
    public static $keep;
    public $cb;
    public function run() { $this->cb = $this->m(...); }
    public function m() {}
    public function __destruct() { echo "dtor H\n"; }
}
class S { public static $o; }
function f() { static $x; $x = new D("fstatic"); }

S::$o = new D("static");
$h = new H;
$h->run();
$c = new D("cycle");
$c->self = $c;
f();
$tmp = new D("released");
$tmp = null;
register_shutdown_function(function () { echo "shutdown fn\n"; });
echo "end\n";
if (($argv[1] ?? '') === 'exit') {
    exit(0);
}
