<?php
// $GLOBALS with a computed name: read, write, isset, unset — both for names the
// program spells elsewhere and for names only ever built at run time.
$wp_query = 'q0';
function set_globals(array $vars): void {
    foreach ($vars as $k => $v) { $GLOBALS[$k] = $v; }
}
function named(): string { global $wp_query; return (string)$wp_query; }
set_globals(['wp_query' => 'q1', 'only_dynamic' => [1, 2]]);
echo named(), "\n";
$k = 'only_' . 'dynamic';
var_dump(isset($GLOBALS[$k]), is_array($GLOBALS[$k]), count($GLOBALS[$k]));
var_dump(isset($GLOBALS['nope' . $k]));
$n = 'wp_query';
echo $GLOBALS[$n], "\n";
$GLOBALS[$n] .= '!';
echo $wp_query, "\n";
unset($GLOBALS[$n]);
var_dump(isset($wp_query));
echo $GLOBALS['missing' . $n] ?? 'default', "\n";
