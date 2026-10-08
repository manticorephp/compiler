<?php
// A foreach over a cell array value whose body writes new keys into that same value leaks the value's buffers.
// issue: #151
function make(): array
{
    return [0 => ['a' => 1, 'b' => 'x']];
}
function churn(int $n): int
{
    $c = 0;
    for ($i = 0; $i < $n; $i++) {
        foreach (array_reverse(make(), true) as $element) {
            foreach ($element as $k => $v) {
                $element[$k . 'x'] = $v;
            }
            $c += count($element);
        }
    }
    return $c;
}
churn(2000);
$before = memory_get_peak_usage();
churn(200000);
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
