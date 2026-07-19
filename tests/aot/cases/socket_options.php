<?php

// socket options: block/nonblock toggles, integer + struct (timeout) options,
// and last_error/strerror. Values that are byte-stable across runtimes.

$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

var_dump(socket_set_nonblock($s));
var_dump(socket_set_block($s));

var_dump(socket_set_option($s, SOL_SOCKET, SO_REUSEADDR, 1));
var_dump(socket_get_option($s, SOL_SOCKET, SO_REUSEADDR) > 0);

var_dump(socket_set_option($s, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]));
$tv = socket_get_option($s, SOL_SOCKET, SO_RCVTIMEO);
var_dump($tv['sec']);
var_dump($tv['usec']);

// SO_TYPE reads back the socket type (SOCK_STREAM).
var_dump(socket_get_option($s, SOL_SOCKET, SO_TYPE));

// A failing connect surfaces an errno + message.
$c = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
@socket_connect($c, '127.0.0.1', 1);
var_dump(socket_last_error($c) !== 0);
var_dump(strlen(socket_strerror(socket_last_error($c))) > 0);
socket_clear_error($c);
var_dump(socket_last_error($c));

socket_close($s);
socket_close($c);
