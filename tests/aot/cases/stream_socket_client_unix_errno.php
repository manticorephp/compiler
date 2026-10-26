<?php
// stream_socket_client over unix:// reports the connect's errno and strerror, as php does.
$dir = sys_get_temp_dir() . '/mc_unix_errno_' . getmypid();
@mkdir($dir);
$s = @stream_socket_client('unix://' . $dir . '/missing.sock', $en, $es, 1);
var_dump($s, $en, $es);
touch($dir . '/plain');
$s = @stream_socket_client('unix://' . $dir . '/plain', $en, $es, 1);
var_dump($s, $en === 0, $es !== '');
unlink($dir . '/plain');
rmdir($dir);
