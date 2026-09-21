<?php
// An uninitialised static local typed by its FIRST store (an object array);
// a later store hands it an ERASED element read (a bare `array` param) that the
// type scan ignores, so the cell keeps releasing at the object-array flavour.
final class Obj
{
    public function __construct(public string $name) {}
}
function remember(int $mode, array $src): int
{
    static $cache;
    if ($mode === 0) {
        $cache = [new Obj('first'), new Obj('second')];
    } else {
        $cache = $src[0];
    }
    return count($cache);
}
function held(Obj $a, Obj $b): mixed
{
    return [[$a, $b]];
}
$a = new Obj('alpha');
$b = new Obj('beta');
$h = held($a, $b);
echo remember(0, $h), "\n";
echo remember(1, $h), "\n";
echo remember(0, $h), "\n";
echo remember(1, $h), "\n";
echo $a->name, ' ', $b->name, ' ', count($h), "\n";
