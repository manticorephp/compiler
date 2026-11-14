<?php
// An array of strings nested five levels deep, overwritten and released: the
// element-release helper stops at three levels and walks the rest by repr.
/** @return array<int, array<int, array<int, array<int, array<int, string>>>>> */
function deep(string $s): array { return [[[[[$s, $s . '!']]]]]; }
function run(): void {
    $a = deep('x');
    $a[0][0][0][0][] = 'y';
    echo json_encode($a), "\n";
    $a = deep('z');
    echo count($a[0][0][0][0]), "\n";
}
run();
