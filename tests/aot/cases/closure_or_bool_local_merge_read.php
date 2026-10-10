<?php
function id(?Closure $c): ?Closure { return $c; }
function run(bool $a, ?Closure $render): string {
    if ($a) {
        $h = true;
    } else {
        $h = id($render);
    }
    if (!$h) { return 'falsy'; }
    return is_callable($h) ? 'closure:' . $h() : 'bool';
}
echo run(true, fn() => 1), "\n";
echo run(false, fn() => 7), "\n";
echo run(false, null), "\n";
