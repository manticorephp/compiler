<?php
function fold(): array {
    return ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd', 'E' => 'e', 'F' => 'f', 'G' => 'g', 'H' => 'h',
            'I' => 'i', 'J' => 'j', 'K' => 'k', 'L' => 'l', 'M' => 'm', 'N' => 'n', 'O' => 'o', 'P' => 'p',
            'Q' => 'q', 'R' => 'r', 'S' => 's', 'T' => 't', 'Ä' => 'ä', 'Ö' => 'ö'];
}
function primes(): array {
    return [2, 3, 5, 7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47, 53, 59, 61, 67, 71, -1, 0];
}
function words(): array {
    return ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa',
            'lambda', 'mu', 'nu', 'xi', 'omicron', 'pi', 'rho', 'sigma', 'tau', 'upsilon'];
}
function codes(): array {
    return ['x' => 1, 'y' => 2, 'z' => 3, 'w' => 4, 'v' => 5, 'u' => 6, 't' => 7, 's' => 8,
            'r' => 9, 'q' => 10, 'p' => 11, 'o' => 12, 'n' => 13, 'm' => 14, 'l' => 15, 'k' => 16];
}
$f = fold();
echo count($f), ' ', $f['Q'], ' ', $f['Ä'], ' ', isset($f['Z']) ? 'y' : 'n', "\n";
echo strtr('HELLO WORLD', $f), "\n";
$g = $f;
$g['Z'] = 'z';
unset($g['A']);
echo count($f), ' ', count($g), ' ', $f['A'], ' ', $g['Z'], "\n";
$p = primes();
echo array_sum($p), ' ', $p[19], ' ', end($p), ' ', count($p), "\n";
$p[] = 73;
echo implode(',', array_slice($p, -3)), "\n";
$w = words();
foreach ($w as $i => $s) { if ($i % 5 === 0) { echo $i, '=', $s, ' '; } }
echo "\n", json_encode(array_slice($w, 0, 4)), "\n";
$c = codes();
arsort($c);
echo json_encode(array_slice($c, 0, 3, true)), ' ', array_search(9, codes()), "\n";
for ($k = 0; $k < 3; $k++) { $t = fold(); $t['A'] = (string)$k; echo $t['A']; }
echo ' ', fold()['A'], "\n";
