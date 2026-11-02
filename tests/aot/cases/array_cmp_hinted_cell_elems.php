<?php
// A cell-typed array channel may be backed by a raw-hinted buffer: ===, == and <=> must decode elements by the buffer's hint.

/** @param int[] $a */
function keep(array $a): array { return $a; }
function viaMixed(mixed $v): array { return keep($v); }

final class Box
{
    /** @var string[] */
    public array $a;
    /** @param string[] $a */
    public function __construct(array $a) { $this->a = $a; }
}
function make(mixed $v): Box { return new Box($v); }

$i = [1, 2];
$c = viaMixed($i);
var_dump($c === $i, $i === $c, $c == $i, $c === [1, 2], $c !== $i);
var_dump($c <=> $i, $c <=> [1, 3], [1, 1] <=> $c);

$s = ['a', 'b'];
$d = viaMixed($s);
var_dump($d === $s, $d == $s, $d === ['a', 'c']);

$m = ['x' => 1, 'y' => 2];
$e = viaMixed($m);
var_dump($e === $m, $e == ['y' => 2, 'x' => 1], $e === ['y' => 2, 'x' => 1]);

$p = ['a'];
$b = new Box($p);
var_dump($b->a === $p, make($p)->a === $p, make($p)->a === ['b']);
