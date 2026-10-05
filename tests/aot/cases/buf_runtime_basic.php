<?php
$h = __mc_nbuf_alloc(3, 3);
__mc_nbuf_set_i($h, 0, 7); __mc_nbuf_set_i($h, 2, -5);
echo __mc_nbuf_len($h), ' ', __mc_nbuf_get_i($h, 0), ' ', __mc_nbuf_get_i($h, 1), ' ', __mc_nbuf_get_i($h, 2), "\n";
$h = __mc_nbuf_insert($h, 1, 2);
echo __mc_nbuf_len($h), ' ', __mc_nbuf_get_i($h, 3), "\n";
__mc_nbuf_remove($h, 0, 1);
echo __mc_nbuf_get_i($h, 0), ' ', __mc_nbuf_find_i($h, -5, 0), "\n";
__mc_nbuf_move($h, 3, 0, 1);
__mc_nbuf_fill_i($h, 9, 1, 3);
echo __mc_nbuf_get_i($h, 0), ' ', __mc_nbuf_get_i($h, 1), ' ', __mc_nbuf_get_i($h, 2), ' ', __mc_nbuf_get_i($h, 3), "\n";
$h = __mc_nbuf_resize($h, 1);
echo __mc_nbuf_len($h), "\n";
$h = __mc_nbuf_resize($h, 40);
echo __mc_nbuf_len($h), ' ', __mc_nbuf_get_i($h, 0), ' ', __mc_nbuf_get_i($h, 39), "\n";

$kinds = [1 => [-128, 127], 2 => [-32768, 32767], 3 => [-2147483648, 2147483647], 4 => [PHP_INT_MIN, PHP_INT_MAX],
    5 => [0, 255], 6 => [0, 65535], 7 => [0, 4294967295]];
foreach ($kinds as $kind => $mm) {
    $b = __mc_nbuf_alloc($kind, 2);
    __mc_nbuf_set_i($b, 0, $mm[0]); __mc_nbuf_set_i($b, 1, $mm[1]);
    echo $kind, ': ', __mc_nbuf_get_i($b, 0), ' ', __mc_nbuf_get_i($b, 1), "\n";
    __mc_nbuf_free($b);
}

$bits = __mc_nbuf_alloc(10, 130);
__mc_nbuf_set_i($bits, 0, 1); __mc_nbuf_set_i($bits, 63, 1); __mc_nbuf_set_i($bits, 64, 1); __mc_nbuf_set_i($bits, 129, 1);
__mc_nbuf_set_i($bits, 63, 0);
echo __mc_nbuf_get_i($bits, 0), __mc_nbuf_get_i($bits, 1), __mc_nbuf_get_i($bits, 63), __mc_nbuf_get_i($bits, 64), __mc_nbuf_get_i($bits, 129),
    ' ', __mc_nbuf_find_i($bits, 1, 1), "\n";
$bits = __mc_nbuf_insert($bits, 1, 3);
echo __mc_nbuf_len($bits), ' ', __mc_nbuf_find_i($bits, 1, 1), "\n";
__mc_nbuf_remove($bits, 0, 60);
echo __mc_nbuf_len($bits), ' ', __mc_nbuf_find_i($bits, 1, 0), ' ', __mc_nbuf_find_i($bits, 1, 8), "\n";

$f = __mc_nbuf_alloc(8, 1); __mc_nbuf_set_f($f, 0, 0.1); var_dump(__mc_nbuf_get_f($f, 0));
$g = __mc_nbuf_alloc(9, 3); __mc_nbuf_fill_f($g, 0.1, 0, 2); var_dump(__mc_nbuf_get_f($g, 1), __mc_nbuf_get_f($g, 2));
echo __mc_nbuf_find_f($g, 0.0, 0), ' ', __mc_nbuf_find_f($g, 2.5, 0), "\n";
$g2 = __mc_nbuf_alloc(9, 2); __mc_nbuf_copy($g2, 0, $g, 1, 2); var_dump(__mc_nbuf_get_f($g2, 0), __mc_nbuf_get_f($g2, 1));

$c = __mc_nbuf_alloc(11, 2); __mc_nbuf_set_c($c, 0, "str"); __mc_nbuf_set_c($c, 1, [1, 2]);
$d = __mc_nbuf_clone($c); __mc_nbuf_set_c($c, 0, "x");
var_dump(__mc_nbuf_get_c($d, 0), __mc_nbuf_get_c($d, 1), __mc_nbuf_get_c($c, 0));
$d = __mc_nbuf_insert($d, 0, 1);
var_dump(__mc_nbuf_get_c($d, 0), __mc_nbuf_get_c($d, 1));
__mc_nbuf_free($c); __mc_nbuf_free($d); __mc_nbuf_free($h); __mc_nbuf_free($f); __mc_nbuf_free($g); __mc_nbuf_free($g2); __mc_nbuf_free($bits);
echo "ok\n";
