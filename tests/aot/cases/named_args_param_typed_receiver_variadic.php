<?php
final class Rec { public function __construct(public string $m = '', public array $c = []) {}
  public function with(mixed ...$args): self { $args['m'] ??= $this->m; $args['c'] ??= $this->c; return new self(...$args); } }
final class Other { public function with(int $x): int { return $x; } }
final class Other2 { public function with(string $a, string $b = 'b'): string { return $a . $b; } }
function p(Rec $record): Rec { return $record->with(c: ['x'], m: strtoupper($record->m)); }
$r = p(new Rec('hi', [])); echo $r->m, count($r->c), "\n";
echo (new Other)->with(3), (new Other2)->with(b: 'B', a: 'A'), "\n";
