<?php
// A scalar read straight off a call's fresh array — `stats()['fires']`. The
// temp is released once the element is out, but a SHAPE read (and a read over a
// buffer a cell writer may have cellified) still decodes the word by the
// buffer's own hint after that, so the release went first and the decode read
// the header of a freed buffer. musl's allocator unmapped it: SIGSEGV.

/** @return array{fires: int, ratio: float, ok: bool} */
function stats(int $i): array
{
    return ['fires' => $i * 3, 'ratio' => $i / 4, 'ok' => $i % 2 === 0];
}

/** @return array<string, mixed> */
function loose(int $i): array
{
    return ['n' => $i, 's' => 'x' . $i];
}

$sum = 0;
$r = 0.0;
$even = 0;
for ($i = 1; $i <= 200; $i++) {
    $sum += stats($i)['fires'];
    $r += stats($i)['ratio'];
    if (stats($i)['ok']) { $even++; }
    $sum += loose($i)['n'];
}
echo $sum, " ", $r, " ", $even, "\n";

$f = function (): string {
    return 'in closure: ' . (string)stats(7)['fires'];
};
echo $f(), "\n";
