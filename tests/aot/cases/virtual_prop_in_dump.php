<?php
class K
{
    public int $real = 1;
    public int $virt { get => 42; }
    public string $back { set => strtoupper($value); }
}

$k = new K();
$k->back = 'x';
var_dump($k);
var_dump((array) $k);
print_r($k);
echo serialize($k), "\n";
var_export($k);
echo "\n";
foreach ($k as $n => $v) {
    echo $n, "\n";
}
