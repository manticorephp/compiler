<?php

// An int read out of an ERASED (mixed-typed) array element, used in BITWISE
// arithmetic. `+` and `<` went through the cell-unboxing operand coercion and
// answered correctly; `|`, `&`, `^`, `<<`, `>>` and `~` did not, and read the
// raw NaN-boxed carrier instead — a silent wrong answer, not a crash. The
// values below are php's; without the fix the bitwise lines print addresses.

$m = ['abc', 7, 0, 255];

echo $m[1] + 1, "\n";
echo $m[1] | 8, "\n";
echo $m[1] & 6, "\n";
echo $m[1] ^ 5, "\n";
echo $m[1] << 2, "\n";
echo $m[3] >> 4, "\n";
echo ~$m[1], "\n";

// Through a local, and with the erased element on the RIGHT.
$i = $m[1];
$z = $m[2];
echo $i | 8, ' ', 8 | $i, ' ', $z | 65, ' ', 1 << $i, "\n";

// The shape that found it: a bit buffer refilled from a byte of a string held
// in the same erased array.
function refill(array &$st, int $need): int
{
    $buf = $st[2];
    $cnt = $st[3];
    while ($cnt < $need) {
        $buf = $buf | (ord($st[0][$st[1]]) << $cnt);
        $st[1] = $st[1] + 1;
        $cnt = $cnt + 8;
    }
    $st[2] = $buf >> $need;
    $st[3] = $cnt - $need;
    return $buf & ((1 << $need) - 1);
}
$st = ["\xcb\x48", 0, 0, 0];
echo refill($st, 1), ' ', refill($st, 2), ' ', refill($st, 5), ' ', refill($st, 8), "\n";

// A cell that came out of a function, not an array: int|false is the same slot.
function pick(int $n) { return $n < 0 ? false : $n; }
$p = pick(12);
echo $p | 3, ' ', $p << 1, ' ', $p & 8, "\n";
