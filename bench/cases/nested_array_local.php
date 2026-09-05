<?php
// nested_array_local — a local holding an array OF ARRAYS. The release-flavor
// family had no member for an ARRAY element, so `discardReleaseFlavor` fell
// through to the plain repr walk: the outer buffer was freed and every inner
// array stranded. `[[1,2],[3]]` rebuilt 200k times leaked 32 MB with nothing
// but int literals inside.
//
// `vecarr` / `assocarr` close it, and the LEAK table is where the closing is
// visible — the parity check alone never saw this, because a leak is not a
// wrong answer. The nested STRING elements are still open
// (tools/prof/packleak.php `local` / `nested`): the inner array's own release
// is repr-driven and its producer stamps no repr, so this row keeps int and
// literal nesting, which is the half that is flat.

/** @return int[] */
function na_nums(int $i): array
{
    return [$i, $i + 1, $i + 2];
}

$n = 120000 * $argc;
$sum = 0;
$t0 = microtime(true);
for ($i = 0; $i < $n; $i++) {
    // A nested literal of literals — the shape with nothing but buffers.
    $grid = [[$i, $i + 1], [$i + 2]];
    $sum += count($grid) + count($grid[1]) + $grid[0][1];
    // Nested CALL results, not literals — the other producer of an owned
    // element. NO alias of the whole thing here: `$q = $r` COPIES the buffer
    // and leaks 69 MB per 200k on its own, before and after this row's fix
    // (tools/prof/packleak.php `copy`), which would drown the signal.
    $rows = [na_nums($i), na_nums($i + 1)];
    $sum += count($rows) + count($rows[1]) + $rows[0][2];
    // Overwrite with a different nesting, so the drop path runs every turn.
    $rows = [[$i]];
    $sum += count($rows);
}
$ms = (microtime(true) - $t0) * 1000.0;
echo $sum, "\n";
fprintf(STDERR, "nested_array_local %.1f ms\n", $ms);
