<?php
$r = getrusage();
echo implode(',', array_keys($r)), "\n";
var_dump(count(array_filter($r, 'is_int')) === count($r), getrusage(-1) !== false);
$g = gettimeofday();
echo implode(',', array_keys($g)), "\n";
var_dump(is_int($g['sec']), $g['usec'] >= 0 && $g['usec'] < 1000000, is_int($g['minuteswest']), is_int($g['dsttime']));
var_dump(gettimeofday(true) > 1.0e9);
$l = sys_getloadavg();
var_dump(count($l), is_float($l[0]));
var_dump(is_string(get_current_user()), is_int(getmyuid()), is_int(getmygid()));
var_dump(phpversion() === PHP_VERSION, phpversion('json') === PHP_VERSION, phpversion('nonesuch'));
