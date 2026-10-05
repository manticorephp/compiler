<?php
function g(string $min): string {
    $parts = explode('.', $min);
    return \sprintf('%s.%s', (int) $parts[0], (int) ($parts[1] ?? 0));
}
$i = 7;
echo sprintf('%s', 5), "\n";
echo sprintf('%s|%s|%s|%s', $i, 1.5, true, null), "\n";
echo sprintf('[%5s][%-4s]', 42, 7), "\n";
echo g('8.2'), ' ', g('7'), "\n";
printf("%s-%s\n", $i + 1, 'x');
