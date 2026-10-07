<?php
// What a generator frame owns, and when it lets go: params from creation,
// locals while suspended, the key, the sent value, the return value.
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
function byParam(D $p, string $tag): \Generator { yield $tag; yield $p->n; }
function unstarted(): void { $g = byParam(new D('unstarted'), 't'); unset($g); echo "unstarted gone\n"; }
function started(): void { $g = byParam(new D('started'), 't'); echo $g->current(), "\n"; unset($g); echo "started gone\n"; }
function finished(): void { $g = byParam(new D('finished'), 't'); foreach ($g as $v) { echo $v, "\n"; } echo "loop done\n"; unset($g); echo "finished gone\n"; }
unstarted(); started(); finished();

function nested(): \Generator
{
    $a = new D('outer');
    try {
        $b = new D('inner');
        try { yield 1; } finally { echo "fin inner\n"; }
        yield 2;
    } catch (\Throwable $e) {
        echo "never\n";
    } finally {
        echo "fin outer\n";
    }
}
function nest1(): void { $g = nested(); $g->current(); unset($g); echo "nest1 gone\n"; }
function nest2(): void { $g = nested(); $g->current(); $g->next(); unset($g); echo "nest2 gone\n"; }
nest1(); nest2();

function keys(): \Generator { yield 'k' . \strlen('ab') => 1; yield 'last' . \strlen('abc') => 2; }
function keyAfter(): void { foreach (keys() as $k => $v) { } echo "key after: ", $k, " ", $v, "\n"; }
keyAfter();

function sink(): \Generator { $x = yield 1; $y = yield 2; echo "got ", $x->n, " ", $y === null ? 'null' : 'set', "\n"; }
function sent(): void { $g = sink(); $g->current(); $g->send(new D('sent')); $g->next(); echo "sent done\n"; }
sent();

final class Box { public function __construct(public D $d) {} }
function ret(Box $b): \Generator { yield 1; return $b->d; }
function retTwice(): void
{
    $b = new Box(new D('ret'));
    $g = ret($b);
    foreach ($g as $v) { }
    echo $g->getReturn()->n, " ", $g->getReturn()->n, "\n";
    unset($g);
    echo "box still has ", $b->d->n, "\n";
}
retTwice();

function boom(): \Generator { $o = new D('boom-local'); yield 1; throw new \RuntimeException('x'); }
function thrown(): void
{
    $g = boom();
    $g->current();
    try { $g->next(); } catch (\RuntimeException $e) { echo "caught ", $e->getMessage(), "\n"; }
    var_dump($g->valid());
    $g->next();
    echo "thrown done\n";
}
thrown();

function once(): \Generator { echo "body\n"; yield 1; }
function again(): void { $g = once(); foreach ($g as $v) { } $g->next(); $g->next(); var_dump($g->valid()); }
again();
echo "end\n";
