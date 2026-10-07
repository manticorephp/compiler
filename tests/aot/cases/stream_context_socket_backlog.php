<?php
// socket.backlog rides a stream context: create, set (both forms), read back, listen with it.
$c = stream_context_create(['socket' => ['backlog' => 5]]);
var_dump(stream_context_get_options($c));
stream_context_set_option($c, 'socket', 'backlog', 64);
var_dump(stream_context_get_options($c)['socket']);
stream_context_set_options($c, ['socket' => ['backlog' => 128]]);
var_dump(stream_context_get_options($c)['socket']['backlog']);
$d = stream_context_create(['ssl' => ['verify_peer' => false]]);
var_dump(isset(stream_context_get_options($d)['socket']));
$s = stream_socket_server('tcp://127.0.0.1:0', $no, $str, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $c);
var_dump($s !== false);
$name = stream_socket_get_name($s, false);
$cl = stream_socket_client('tcp://' . $name);
$a = stream_socket_accept($s);
fwrite($cl, "hi");
var_dump(fread($a, 2));
