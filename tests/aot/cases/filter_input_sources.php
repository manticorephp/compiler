<?php
// filter_input / filter_input_array / filter_has_var over the runtime's request arrays.
// php-cli carries no request data, so there is no Zend oracle: expected output written by hand.
function show(string $label, callable $f): void {
    try { $r = $f(); echo $label, ' => ', $r, "\n"; }
    catch (Throwable $e) { echo $label, ' => ', get_class($e), ': ', $e->getMessage(), "\n"; }
}
$_GET = ['a' => '5', 'b' => 'x', 'arr' => ['1', '2', 'y'], 'mail' => 'a@b.co'];
$_POST = ['p' => 'yes'];
$_COOKIE = ['c' => '1'];
$_SERVER['MC_T'] = '42';
putenv('MC_ENV=7');
show('has get a', fn() => var_export(filter_has_var(INPUT_GET, 'a'), true));
show('has get zz', fn() => var_export(filter_has_var(INPUT_GET, 'zz'), true));
show('has post p', fn() => var_export(filter_has_var(INPUT_POST, 'p'), true));
show('has cookie c', fn() => var_export(filter_has_var(INPUT_COOKIE, 'c'), true));
show('has server', fn() => var_export(filter_has_var(INPUT_SERVER, 'MC_T'), true));
show('has env', fn() => var_export(filter_has_var(INPUT_ENV, 'MC_ENV'), true));
show('has bad type', fn() => var_export(filter_has_var(3, 'a'), true));
show('input a int', fn() => var_export(filter_input(INPUT_GET, 'a', FILTER_VALIDATE_INT), true));
show('input b int', fn() => var_export(filter_input(INPUT_GET, 'b', FILTER_VALIDATE_INT), true));
show('input b int null', fn() => var_export(filter_input(INPUT_GET, 'b', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE), true));
show('input missing', fn() => var_export(filter_input(INPUT_GET, 'zz'), true));
show('input missing nullfail', fn() => var_export(filter_input(INPUT_GET, 'zz', FILTER_DEFAULT, FILTER_NULL_ON_FAILURE), true));
show('input missing default', fn() => var_export(filter_input(INPUT_GET, 'zz', FILTER_VALIDATE_INT, ['options' => ['default' => 9]]), true));
show('input array scalar', fn() => var_export(filter_input(INPUT_GET, 'arr', FILTER_VALIDATE_INT), true));
show('input array', fn() => var_export(filter_input(INPUT_GET, 'arr', FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY), true));
show('input mail', fn() => var_export(filter_input(INPUT_GET, 'mail', FILTER_VALIDATE_EMAIL), true));
show('input post bool', fn() => var_export(filter_input(INPUT_POST, 'p', FILTER_VALIDATE_BOOL), true));
show('input cookie', fn() => var_export(filter_input(INPUT_COOKIE, 'c', FILTER_VALIDATE_INT), true));
show('input server', fn() => var_export(filter_input(INPUT_SERVER, 'MC_T', FILTER_VALIDATE_INT), true));
show('input env', fn() => var_export(filter_input(INPUT_ENV, 'MC_ENV', FILTER_VALIDATE_INT), true));
show('input throw', fn() => var_export(filter_input(INPUT_GET, 'b', FILTER_VALIDATE_INT, FILTER_THROW_ON_FAILURE), true));
show('input bad type', fn() => var_export(filter_input(7, 'a'), true));
show('array all', fn() => var_export(filter_input_array(INPUT_POST, FILTER_VALIDATE_BOOL), true));
show('array spec', fn() => var_export(filter_input_array(INPUT_GET, ['a' => FILTER_VALIDATE_INT, 'b' => ['filter' => FILTER_VALIDATE_INT, 'flags' => FILTER_NULL_ON_FAILURE], 'nope' => FILTER_DEFAULT]), true));
show('array spec no empty', fn() => var_export(filter_input_array(INPUT_GET, ['a' => FILTER_VALIDATE_INT, 'nope' => FILTER_DEFAULT], false), true));
show('array nested', fn() => var_export(filter_input_array(INPUT_GET, ['arr' => ['filter' => FILTER_VALIDATE_INT, 'flags' => FILTER_REQUIRE_ARRAY]]), true));
$_COOKIE = [];
show('array empty source', fn() => var_export(filter_input_array(INPUT_COOKIE), true));
show('array empty source nullfail', fn() => var_export(filter_input_array(INPUT_COOKIE, ['flags' => FILTER_NULL_ON_FAILURE]), true));
