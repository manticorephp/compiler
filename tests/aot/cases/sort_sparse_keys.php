<?php
// sort/rsort/usort on a sparse or string-keyed array sort its VALUES and
// reindex, as php does (php-cs-fixer sorts `$cases[$index] = new CaseAnalysis`).
final class CaseA { public function __construct(private int $index, private int $colon) {} public function c(): int { return $this->colon; } }
final class Sw { /** @param list<CaseA> $cases */ public function __construct(private array $cases) {} /** @return list<CaseA> */ public function getCases(): array { return $this->cases; } }
function build(array $raw): Sw {
    $cases = [];
    foreach ($raw as $case) { $cases[$case['index']] = new CaseA($case['index'], $case['open']); }
    sort($cases);
    return new Sw($cases);
}
$s = build([['index' => 30, 'open' => 33], ['index' => 10, 'open' => 12], ['index' => 20, 'open' => 25]]);
foreach ($s->getCases() as $c) { echo $c->c(), ' '; }
echo "\n";
final class A3 { public function __construct(public int $i) {} }
$c = [];
foreach ([30, 10] as $i) { $c[$i] = new A3($i); }
echo count($c), "\n";
sort($c);
echo count($c), "\n";
echo $c[0]->i, "\n";
$d = [new A3(3), new A3(1)]; sort($d); echo $d[0]->i, "\n";
$m = ["b" => 3, "a" => 1, "c" => 2]; rsort($m); var_dump($m);
$u = [5 => "x", 2 => "yy", 9 => "z"]; usort($u, fn ($p, $q) => strlen($q) <=> strlen($p)); var_dump($u);
