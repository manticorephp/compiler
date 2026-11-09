<?php
// getcwd() is cached and follows chdir(): the new directory after a change, the old one after changing back.
$start = getcwd();
$tmp = sys_get_temp_dir() . '/mc_cwd_' . getmypid();
@mkdir($tmp);
echo getcwd() === $start ? "same\n" : "moved\n";
var_dump(chdir($tmp));
echo basename(getcwd()) === basename($tmp) ? "in tmp\n" : "not in tmp: " . getcwd() . "\n";
echo realpath('.') === realpath($tmp) ? "realpath ok\n" : "realpath off\n";
var_dump(@chdir($tmp . '/does-not-exist'));
echo basename(getcwd()) === basename($tmp) ? "still in tmp\n" : "lost\n";
var_dump(chdir($start));
echo getcwd() === $start ? "back\n" : "not back\n";
rmdir($tmp);
