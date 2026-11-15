<?php
// Arrays of enum cases crossing into erased (bare `array`) slots read back as the cases.

enum Level: int { case Debug = 100; case Info = 200; }
enum Suit { case Hearts; case Spades; }

final class Box
{
    public array $items = [];
    public static array $all = [];
    public function set(array $v): void { $this->items = $v; }
    public function head(array $v) { return $v[0]; }
}

function first(array $x) { return $x[0]; }
function each_(array $xs): void { foreach ($xs as $k => $v) { echo $k, '=', $v->name, "\n"; } }
function dump(array $x): void { var_dump($x); print_r($x); echo "\n"; }
function same(array $x): void { var_dump($x[0] === Suit::Spades, in_array(Suit::Spades, $x, true), $x == [Suit::Spades]); }
function mixedRet(bool $b): array { if ($b) { return [Suit::Spades]; } return ['x']; }
function wrap(array $x): array { return ['in' => $x]; }

$b = new Box;
$b->items = [Suit::Spades, Suit::Hearts];
var_dump($b->items[0]);
$b->set([Level::Info]);
var_dump($b->items[0]);
var_dump($b->head([Suit::Hearts]));
Box::$all = [Suit::Hearts];
var_dump(Box::$all[0]);
echo first([Level::Info])->name, "\n";
echo first([Level::Debug])->name, "\n";
each_([Suit::Spades, Suit::Hearts]);
dump(['a' => Level::Debug]);
same([Suit::Spades]);
$fn = function (array $x) { var_dump($x[0]); };
$fn([Suit::Spades]);
var_dump(mixedRet(true)[0]);
var_dump(wrap([Level::Info])['in'][0]);
$local = [Level::Debug, Level::Info];
echo first($local)->name, "\n"; var_dump($local[1]);
echo json_encode(['l' => $local]), "\n";
function erasedRet(int $n): array { $r = []; for ($i = 0; $i < $n; $i++) { $r[] = $i % 2 ? Suit::Spades : Suit::Hearts; } return $r; }
var_dump(erasedRet(2)[1]);
$b->items = ['k' => $local];
var_dump($b->items['k'][1]);
Box::$all = ['k' => [Suit::Spades]];
var_dump(Box::$all['k'][0]);
for ($i = 0; $i < 3; $i++) { $b->set([Suit::Hearts]); Box::$all = $local; echo $b->items[0]->name, Box::$all[1]->name, "\n"; }
