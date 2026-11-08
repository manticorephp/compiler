<?php
// `$map[$k]->p ?? $d` on a missing element is the default, not an error on null.
final class M { public ?string $opt = null; public function __construct(public string $name) {} }
final class H { /** @var array<string, M> */ public array $meta = [];
  public function show(string $s): string { return $this->meta[$s]->name ?? $s; } }
$h = new H; $h->meta['a'] = new M('A');
echo $h->show('a'), ' ', $h->show('z'), "\n";
/** @var array<string, M> $m */
$m = ['x' => new M('X')]; $k = 'q';
echo $m[$k]->name ?? 'typed', ' ', $m['x']->name ?? 'no', ' ', $m['x']->opt ?? 'optnull', "\n";
$e = ['y' => new M('Y'), 'n' => null, 3 => 5];
foreach (['y', 'n', 'gone'] as $key) { echo $e[$key]->name ?? 'erased', ' ', $e[$key]?->name ?? 'ns', ' '; }
function er(array $a, string $k): string { return $a[$k]->name ?? 'fn'; }
echo er(['y' => new M('Y'), 'n' => null], 'n'), er(['y' => new M('Y')], 'z'), er(['y' => new M('Y')], 'y'), ' ';
echo "\n";
function side(): M { echo "once "; return new M('S'); }
$arr = ['s' => side()];
echo $arr[strtolower('S')]->name ?? 'd', "\n";
$o = [[new M('deep')]];
echo $o[0][0]->name ?? 'd1', ' ', $o[0][9]->name ?? 'd2', ' ', $o[7][0]->name ?? 'd3', "\n";
