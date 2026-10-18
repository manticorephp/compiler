<?php
// version_compare: special forms, separators, digit/letter boundaries, empties, overflow, every operator spelling
$v = ['', '1', '1.0', '1.0.0', '1.2.3', '1.10.0', '1.2', '01.2', '1.02', '1..2', '.1', '1.', '1.0.', '-1', '1-2', '1_2', '1+2', '1.0-dev', '1.0dev', '1.0.0-alpha', '1.0a', '1.0alpha1',
      '1.0b', '1.0-beta2', '1.0RC1', '1.0rc1', '1.0-RC.2', '1.0#1', '1.0#', '1.0pl1', '1.0p1', '1.0-patch', '1.0.0.0', '1.0foo', '1.0bar', 'foo', 'bar', 'abc', 'dev', 'alpha', 'beta', 'RC', 'rc', 'pl',
      '5.2', '5.2.0-dev', '5.2.0RC1', '5.2.0rc1', '5.2.0.1', '8.5.0', '8.4.12', '7.0.0beta1', '10', '9', '99999999999999999999', '99999999999999999998', '9223372036854775807', '9223372036854775808',
      '1.0 ', ' 1.0', '1,0', '1.0devel', '1.0de', '1.0R', '1.0RCx', '1.0~1', "1.0\x80", '0', '0.0', '00', '1a', '1a1', 'a1', 'a.1'];
foreach ($v as $a) {
    $line = '';
    foreach ($v as $b) { $line .= version_compare($a, $b) + 1; }
    echo bin2hex($a), ' ', $line, "\n";
}
foreach (['<', 'lt', '<=', 'le', '>', 'gt', '>=', 'ge', '==', 'eq', '!=', '<>', 'ne', 'l', 'g', 'e', 'n', '=', '!', ''] as $op) {
    echo "[$op]";
    foreach ([['1.0', '1.1'], ['1.1', '1.0'], ['1.0', '1.0'], ['1.0-dev', '1.0']] as [$a, $b]) {
        try { echo ' ', var_export(version_compare($a, $b, $op), true); } catch (ValueError $e) { echo ' VE'; }
    }
    echo "\n";
}
foreach (['<<', 'x', 'lte', '=>', '==='] as $op) {
    try { var_dump(version_compare('1', '2', $op)); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
}
