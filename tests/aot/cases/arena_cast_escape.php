<?php
// A cast of a value that already has the target representation is a BORROW:
// `(string)$t` IS $t, so storing the cast stores $t and $t must leave the
// loop's arena. Every shape below reads the last iteration's bytes through
// every element when the cast hides the escape.
function viaAppend(int $n): array
{
    $keep = [];
    for ($i = 0; $i < $n; $i++) {
        $t = "s" . $i;
        $keep[] = (string)$t;
    }
    return $keep;
}

function viaKeyed(int $n): array
{
    $keep = [];
    for ($i = 0; $i < $n; $i++) {
        $t = "k" . $i;
        $keep[$t] = (string)$t;
    }
    return $keep;
}

final class Box
{
    public string $last = '';
    /** @var string[] */
    public array $all = [];
}

function viaProperty(int $n): Box
{
    $b = new Box();
    for ($i = 0; $i < $n; $i++) {
        $t = "p" . $i;
        $b->last = (string)$t;
        $b->all[] = (string)($t . "!");
    }
    return $b;
}

function viaReturn(int $i): string
{
    $t = "r" . $i;
    return (string)$t;
}

echo implode(",", viaAppend(4)), "\n";
foreach (viaKeyed(3) as $k => $v) { echo $k, "=", $v, " "; }
echo "\n";
$b = viaProperty(3);
echo $b->last, " ", implode(",", $b->all), "\n";
$rs = [];
for ($i = 0; $i < 3; $i++) { $rs[] = viaReturn($i); }
echo implode(",", $rs), "\n";
