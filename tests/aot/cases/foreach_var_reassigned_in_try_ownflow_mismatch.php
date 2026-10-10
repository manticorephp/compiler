<?php
// A foreach value variable reassigned from an array element inside try fails MIR.verify ownflow (cell vs mixdead)
function save(array $x, int $l): bool|array { return $l > 5 ? [] : true; }
function commit(array $values, array $by): int {
    $n = 0;
    foreach ($values as $id => $v) {
        $n += (int) $v;
    }
    foreach ($by as $lifetime => $ids) {
        foreach ($ids as $id => $unused) {
            try {
                $v = $by[$lifetime][$id];
                $e = save([$id => $v], $lifetime);
            } catch (\Exception $e) {
            }
            if (true === $e) { continue; }
            echo get_debug_type($v), "\n";
        }
    }
    return $n;
}
echo commit(['a' => 1, 'b' => 'z'], [1 => ['k' => 'v'], 9 => ['q' => 3]]), "\n";
