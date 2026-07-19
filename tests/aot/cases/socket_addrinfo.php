<?php

// socket_addrinfo_lookup / _explain. 127.0.0.1 resolves deterministically, so
// the decoded entry is byte-stable across runtimes.

$hints = ['ai_socktype' => SOCK_STREAM];
$ai = socket_addrinfo_lookup('127.0.0.1', '80', $hints);
var_dump(is_array($ai));
var_dump(count($ai) >= 1);
var_dump($ai[0] instanceof AddressInfo);

$ex = socket_addrinfo_explain($ai[0]);
var_dump($ex['ai_family']);
var_dump($ex['ai_socktype']);
var_dump($ex['ai_protocol']);
var_dump($ex['ai_addr']['sin_port']);
var_dump($ex['ai_addr']['sin_addr']);

// _connect against a live loopback listener built with socket_addrinfo_bind.
$binds = socket_addrinfo_lookup('127.0.0.1', '0', ['ai_socktype' => SOCK_STREAM]);
$srv = socket_addrinfo_bind($binds[0]);
var_dump($srv instanceof Socket);
socket_listen($srv, 5);
$sn = '';
$sp = 0;
socket_getsockname($srv, $sn, $sp);

$targets = socket_addrinfo_lookup('127.0.0.1', (string)$sp, ['ai_socktype' => SOCK_STREAM]);
$cli = socket_addrinfo_connect($targets[0]);
var_dump($cli instanceof Socket);

$conn = socket_accept($srv);
socket_write($cli, "ok");
$buf = '';
socket_recv($conn, $buf, 2, 0);
var_dump($buf);

socket_close($cli);
socket_close($conn);
socket_close($srv);
