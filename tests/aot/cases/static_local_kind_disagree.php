<?php
// A static local declared by a STRING initialiser whose stores disagree in
// kind — an object on one path, a string on the other. The cell must never
// release the object through the string header (rc at ptr-8, free from
// ptr-16) on the next overwrite: the disagreeing stores veto the cell's
// release-before-overwrite, and both paths keep answering.
final class Obj
{
    public function __construct(public string $name) {}
}
function keep(bool $obj): string
{
    static $v = '';
    if ($obj) {
        $v = new Obj('o' . strlen('x'));
    } else {
        $v = 'str' . strlen('yy');
    }
    if ($v instanceof Obj) {
        return $v->name;
    }
    return $v;
}
$objs = [];
for ($i = 0; $i < 3; $i++) {
    echo keep(true), ' ', keep(false), ' ', keep(false), ' ', keep(true), "\n";
    $objs[] = new Obj('keep' . $i);
}
echo count($objs), ' ', $objs[2]->name, "\n";
