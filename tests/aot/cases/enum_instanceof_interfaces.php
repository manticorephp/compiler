<?php
enum Lvl: string { case A = 'a'; case B = 'b'; }
enum Pure { case X; case Y; }
$v = Lvl::A; $p = Pure::X;
var_dump($v instanceof Lvl, $v instanceof UnitEnum, $v instanceof BackedEnum, $v instanceof Pure, $v instanceof Countable);
var_dump($p instanceof Pure, $p instanceof UnitEnum, $p instanceof BackedEnum);
function erased(mixed $o): string { return ($o instanceof UnitEnum ? 'U' : '-') . ($o instanceof BackedEnum ? 'B' : '-'); }
echo erased($v), erased($p), erased(1), erased(new stdClass), "\n";
function names(string $cls): array {
    $out = [];
    foreach ($cls::cases() as $c) {
        if (!$c instanceof UnitEnum) { throw new InvalidArgumentException('not an enum'); }
        $out[] = $c->name;
    }
    return $out;
}
echo implode(',', names(Lvl::class)), ' ', implode(',', names(Pure::class)), "\n";
$c = Lvl::class;
echo $c::from('b')->name, ' ', var_export($c::tryFrom('z'), true), "\n";
