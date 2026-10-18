<?php
$vs = ['', '0', '1', '1.0', '1.0.0', '1.2.3', '1.10.0', '1.2', '1.2.', '.1', '1..2', '1.2.3-dev', '1.2.3-alpha', '1.2.3a', '1.2.3-beta1', '1.2.3b2', '1.2.3RC1', '1.2.3rc1', '1.2.3-rc.2', '1.2.3pl1', '1.2.3-p', '1.2.3#', '1.2.3+build', '1_2_3', '1-2-3', '1.2.3.4.5', '01.002', '99999999999999999999', '9223372036854775808', '9223372036854775807', '1.0.0-dev', '1.0.0-patch', 'abc', 'dev', '5.3.0', '5.3.0RC1', '5.3.0-dev', '5.3', '5', '10.0.0', '2.0.0-beta.2+exp.sha.5114f85', '1.0.x', 'foo.bar'];
foreach ($vs as $a) {
    $row = [];
    foreach ($vs as $b) { $row[] = version_compare($a, $b); }
    echo implode(',', $row), "\n";
}
foreach (['<', 'lt', '<=', 'le', '>', 'gt', '>=', 'ge', '==', '=', 'eq', '!=', '<>', 'ne'] as $op) {
    echo $op, ' ', var_export(version_compare('1.2.3', '1.10.0', $op), true), var_export(version_compare('2', '2.0', $op), true), var_export(version_compare('1.0a', '1.0b', $op), true), "\n";
}
try { version_compare('1', '2', 'bogus'); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
$c = 0; for ($i = 0; $i < 20000; $i++) { $c += version_compare('1.2.3', '1.10.0'); } echo $c, "\n";
