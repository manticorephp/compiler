<?php
// include followed by include_once of the same file runs it twice
// issue: #407
$f = sys_get_temp_dir() . '/mc_inc_once_' . getmypid() . '.php';
file_put_contents($f, '<?php echo "ran\n";');
include $f;
include_once $f;
unlink($f);
