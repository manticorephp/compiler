<?php
namespace App;

function helper() {}
if (!function_exists('poly')) { function poly() {} }

echo strlen('x'), \array_map(fn($x) => $x, []) === [] ? 'a' : 'b', mb_strlen('x');
helper();
poly();
apcu_fetch('k');
apcu_fetch('j');
no_such_fn();
