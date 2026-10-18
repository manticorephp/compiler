<?php
function userfn(string $s): string { return "<$s>"; }
function mkname(int $i): string { return ['str', 'STR', 'Str'][$i % 3] . 'len'; }

$names = ['strlen', 'strtoupper', 'ucfirst', 'userfn', 'STRLEN', '\\strlen', 'UserFn', 'nope_fn', 'strlen', 'strlen'];
foreach ($names as $n) {
    try {
        echo $n, ' => ', var_export($n('hello'), true), "\n";
    } catch (Error $e) {
        echo $n, ' => ', $e->getMessage(), "\n";
    }
}

// same name, different string objects each iteration
$sum = 0;
for ($i = 0; $i < 9; $i++) {
    $f = mkname($i);
    $sum += $f('abcd');
    $g = 'str' . 'len';
    $sum += $g('xy');
}
echo $sum, "\n";

// name changes after many hits on one site
$f = 'strlen';
$acc = '';
for ($i = 0; $i < 12; $i++) {
    if ($i === 6) { $f = 'strtoupper'; }
    if ($i === 9) { $f = 'userfn'; }
    $acc .= $f('ab') . ',';
}
echo $acc, "\n";

// freed and re-allocated names
for ($i = 0; $i < 6; $i++) {
    $n = $i % 2 ? 'strto' . 'upper' : 'str' . 'rev';
    echo $n('abc'), "\n";
    unset($n);
}

// undefined after a hit
$f = 'strlen';
echo $f('abc'), "\n";
$f = 'strlen_';
try { echo $f('abc'); } catch (Error $e) { echo $e->getMessage(), "\n"; }
$f = 'strlen';
echo $f('abc'), "\n";
