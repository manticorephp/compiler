<?php
// A DYNAMIC callable takes its arguments in the same representation a known
// closure does: an object and an array cross as cells, so an untyped
// callback's `return $v` counts what it hands back.
final class O { public function __construct(public string $t) {} public function __destruct() { echo "~{$this->t}\n"; } }
function apply(callable $f, array $s): array { return array_map($f, $s); }
function call1(callable $f, mixed $v): mixed { return $f($v); }
$cbs = [fn($i) => $i, fn($i) => $i]; $id = $cbs[\count($cbs) - 1];
$s = [['s' => 0, 'e' => 'q']]; $t = apply($id, $s); $s[0]['e'] = 'r'; var_dump($t[0]['e']);
$s = [['s' => 0, 'e' => 'q']]; $t = apply($id, $s); $t[0]['e'] = 'r'; var_dump($s[0]['e']);
$tys = [fn(array $row): array => $row, fn(array $row): array => $row]; $typed = $tys[\count($tys) - 1];
$s = [['s' => 0, 'e' => 'q']]; $t = apply($typed, $s); $t[0]['e'] = 'r'; var_dump($s[0]['e']);
function run(callable $id, callable $typed): void {
    $o = $id(new O('a'));
    echo $o->t, "\n";
    $o = null;
    echo "after-a\n";
    $p = $typed(new O('b'));
    echo $p->t, "\n";
    $p = null;
    echo "after-b\n";
    $k = new O('c');
    $x = call1($id, $k);
    $k = null;
    echo "k-null\n";
    $x = null;
    echo "after-c\n";
    $ints = call1($id, [1, 2, 3]);
    echo array_sum($ints), "\n";
}
$tcbs = [fn(O $o): O => $o, fn(O $o): O => $o]; run($id, $tcbs[\count($tcbs) - 1]);
echo "end\n";
