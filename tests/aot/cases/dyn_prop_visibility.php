<?php
// `$o->$name` obeys visibility: Twig's getAttribute saw a private property through
// isset(), read it directly and never called the getter.
class A { public int $pub = 1; protected int $pro = 2; private int $pri = 3; private ?int $nul = null;
    public function own(object $o, string $n): array { return [isset($o->$n), $o->$n ?? 'dflt']; }
    public function write(string $n, int $v): int { $this->$n = $v; return $this->$n; } }
class B extends A { public function sub(object $o, string $n): array { return [isset($o->$n), $o->$n ?? 'dflt']; } }

function outside(mixed $o, string $n): void {
    echo $n, ' isset=', var_export(isset($o->$n), true), ' coalesce=', var_export($o->$n ?? 'dflt', true), ' read=';
    try { echo var_export($o->$n, true), "\n"; } catch (\Error $e) { echo get_class($e), ':', $e->getMessage(), "\n"; }
    try { $o->$n = 9; echo "wrote\n"; } catch (\Error $e) { echo get_class($e), ':', $e->getMessage(), "\n"; }
}
foreach (['pub', 'pro', 'pri', 'nul'] as $n) { outside(new A, $n); }
$a = new A;
foreach (['pub', 'pro', 'pri', 'nul'] as $n) { echo json_encode($a->own(new A, $n)), json_encode((new B)->sub(new A, $n)), "\n"; }
echo $a->write('pri', 7), $a->write('pro', 8), "\n";
$d = new stdClass; $d->x = 1; $n = 'x'; echo var_export(isset($d->$n), true), $d->$n, "\n";
$peek = \Closure::bind(static fn (A $o, string $n) => $o->$n, null, A::class); echo $peek(new A, 'pri'), "\n";
