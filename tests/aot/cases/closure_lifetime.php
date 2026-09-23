<?php
// A closure env is counted like an object: whoever holds it — a local, a
// static, a property — gives it back, and what it captured dies with it. A
// closure that left the frame that built it used to be released by nobody.
class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~{$this->n}\n"; }
}

function mk(string $n) { $o = new D($n); return function () use ($o) { return strlen($o->n); }; }
function mkRef(string $n) { $o = new D($n); return function () use (&$o) { return strlen($o->n); }; }

function scopeExit(): void { $c = mk('scope'); echo $c(), "\n"; echo "end scope\n"; }
scopeExit();
echo "after scope\n";

function unsetIt(): void { $c = mkRef('unset'); echo $c(), "\n"; unset($c); echo "after unset\n"; }
unsetIt();

mk('discarded');
echo "after discard\n";

function keep(): void
{
    static $keep = null;
    if ($keep === null) {
        $o = new D('static');
        $keep = function () use (&$o) { return strlen($o->n); };
        echo "made\n";
        return;
    }
    echo $keep(), "\n";
    $keep = null;
    echo "dropped\n";
}
keep();
echo "after keep\n";
keep();

class H { public ?Closure $h = null; public function get(): Closure { return $this->h; } }
function literalProp(): void { $x = new H(); $d = new D('lit'); $x->h = function () use ($d) { return 1; }; unset($d); unset($x); echo "lit gone\n"; }
literalProp();
function borrowedProp(): void { $f = mk('bor'); $x = new H(); $x->h = $f; unset($x); echo "x gone\n"; unset($f); echo "f gone\n"; }
borrowedProp();
function getter(): void { $x = new H(); $x->h = mk('get'); $g = $x->get(); unset($x); echo "x gone\n"; echo $g(), "\n"; unset($g); echo "g gone\n"; }
getter();

class K { public ?Closure $h = null; }
function overwrite(): void { $x = new K(); $x->h = mk('ow1'); $x->h = mk('ow2'); echo "ow\n"; unset($x); echo "ow gone\n"; }
overwrite();

// A closure returning a property string hands the caller its own count.
class E { public function __construct(public string $n) {} }
function mkAppend() { $o = new E('e'); return function () use ($o) { $o->n .= '!'; return $o->n; }; }
$c = mkAppend();
for ($i = 0; $i < 3; $i++) { $z = str_repeat('z', 3); echo $c(), ' ', $z, "\n"; }
echo "done\n";
