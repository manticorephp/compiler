<?php
class P { public function __toString(): string { return "P!"; } }
$c = "ab";
echo sprintf("%s %s!", $c, ...[$c]), "\n";
printf("%d-%d\n", 1, ...[2]);
$p = new P();
echo sprintf('%s %s %s', $p, ...[$p, 'z']), "\n";
$f = '%s/%d/%s';
echo sprintf($f, 'a', ...[2, 'c']), "\n";
$rest = [3.5, 'q'];
echo sprintf('%d %.1f %s', 7, ...$rest), "\n";
echo sprintf('%s %s %s', 'a', ...['b'], ...['c']), "\n";
printf('%s|%s' . "\n", ...['x', 'y']);
echo min(5, ...[3, 9]), ' ', max(1, ...[7, 2]), "\n";
$a = [9];
array_unshift($a, 1, ...[2, 3]);
echo implode(',', $a), "\n";
function gen() { yield 'g1'; yield 'g2'; }
echo sprintf('%s-%s-%s', 'x', ...gen()), "\n";
echo min('b', ...['a', 'c']), ' ', max(1.5, ...[2.5, 0.5]), ' ', max([1, 2], ...[[1, 3]])[1], "\n";
$m0 = 0;
for ($i = 0; $i < 60000; $i++) {
    $s = sprintf('%s-%s', 'k' . $i, ...['v' . $i]);
    $x = max($i, ...[$i + 1, 'z' . $i]);
    if ($i === 20000) { $m0 = memory_get_usage(); }
}
echo $s, ' ', $x, ' ', memory_get_usage() - $m0 < 1000000 ? 'flat' : 'grows', "\n";
