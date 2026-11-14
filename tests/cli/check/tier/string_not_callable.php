<?php
function help() { missing_from_string_named(); }
function cb($x) { return missing_cb_fn(); }
function run(callable $c) { return $c(); }
function hk() { missing_hk(); }
$msgs = ['x' => 'help'];
echo $msgs['x'];
array_map('cb', [1]);
run('hk');
function sd() { missing_sd(); }
register_shutdown_function('sd');
