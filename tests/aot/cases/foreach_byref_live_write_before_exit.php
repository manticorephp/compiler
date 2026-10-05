<?php
// A live by-ref foreach writes `$v` back at the step; a `break` or a `return`
// leaves before it, so a write made in the body must already be in the element.
function setFirst(array &$a): void { foreach ($a as &$v) { $v = 5; break; } }
function bumpUntil(array &$a, int $stop): bool {
    foreach ($a as $k => &$v) {
        $v = $v * 10;
        if ($k === $stop) { return true; }
    }
    return false;
}
final class Box {
    /** @var array<string, array<int, string>> */
    public array $ls = [];
    public function tag(string $e): void {
        foreach ($this->ls[$e] as &$list) { $list[] = 'z'; break; }
    }
}
$a = [1, 2];
setFirst($a);
echo json_encode($a), "\n";
$b = [1, 2, 3];
var_dump(bumpUntil($b, 1));
echo json_encode($b), "\n";
$c = [[1], [2]];
foreach ($c as &$r) { $r[] = 9; break; }
unset($r);
echo json_encode($c), "\n";
$x = new Box();
$x->ls['e'] = [['a'], ['b']];
$x->tag('e');
echo json_encode($x->ls), "\n";
