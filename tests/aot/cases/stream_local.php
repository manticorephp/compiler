<?php
$m = fopen('php://memory', 'r+');
$f = fopen('/tmp/mc_sl_test.txt', 'w');
$e=0;$em='';
$c = @stream_socket_client('tcp://127.0.0.1:1', $e, $em);   // false (refused)
var_dump(stream_is_local($m));            // true (memory)
var_dump(stream_is_local($f));            // true (file)
var_dump(stream_supports_lock($f));       // true (file)
var_dump(stream_supports_lock($m));       // false (memory)
var_dump(is_int(stream_set_read_buffer($f, 8192)));   // hint; just returns an int
var_dump(is_int(stream_set_write_buffer($f, 8192)));
fclose($f); fclose($m); @unlink('/tmp/mc_sl_test.txt');
echo "done\n";
