<?php
// sprintf/printf results longer than 255 bytes are not clamped (php-cs-fixer's
// diff template cut its hunk off mid-header).
$s = str_repeat('x', 600);
echo strlen(sprintf('%s', $s)), ' ', strlen(sprintf("a\n%s\nb", $s)), ' ', strlen(sprintf('-%s-%s', $s, $s)), "\n";
echo strlen(sprintf('%-300s|', 'ab')), ' ', strlen(sprintf('%05d%s', 7, $s)), "\n";
$t = sprintf("begin\n%s\nend", implode(PHP_EOL, array_fill(0, 40, str_repeat('y', 20))));
echo strlen($t), ' ', substr($t, -8), "\n";
printf("%s|\n", substr($s, 0, 300));
