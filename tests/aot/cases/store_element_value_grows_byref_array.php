<?php
function grow(array &$v, int $d): bool
{
    if ($d >= 40) { return true; }
    $v["a$d"] = grow($v, $d + 1);
    return false;
}
$v = [];
grow($v, 0);
echo count($v), "\n";

function grow2(array &$v, int $d): bool
{
    if ($d >= 40) { return true; }
    return $v["b$d"] ?? $v["b$d"] = grow2($v, $d + 1);
}
$w = [];
grow2($w, 0);
echo count($w), "\n";

final class P
{
    public array $src = [];
    public function isErr(string $id, array &$visited): bool
    {
        if (!isset($this->src[$id])) { return false; }
        if (isset($visited[$id])) { return $visited[$id]; }
        $visited[$id] = true;
        foreach ($this->src[$id] as $sid => $isRuntime) {
            if ($visited[$sid] ?? $visited[$sid] = $this->isErr($sid, $visited)) { continue; }
            if (!$isRuntime) { return false; }
        }
        return true;
    }
}
$p = new P();
$p->src['a'][''] = true;
$p->src['a']['b'] = true;
$p->src['b']['a'] = false;
$p->src['b']['c'] = true;
$p->src['c']['kernel'] = true;
$seen = [];
foreach (['a', 'b', 'c', 'zz'] as $id) {
    echo $id, ' ', var_export($p->isErr($id, $seen), true), "\n";
}
echo count($seen), "\n";
