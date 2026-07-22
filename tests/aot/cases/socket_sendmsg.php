<?php
// socket_sendmsg/recvmsg over a socketpair — iov scatter/gather AND SCM_RIGHTS fd
// passing. Offline + deterministic. #[CellArg] cellifies the message so the
// once-compiled stdlib reads its nested sub-arrays as tagged cells. Verifies the fd
// IS transferred (control received with the right level/type/count); pulling the
// received \Socket object back out of the 3-level output array is subject to a
// separate deep-nested-object repr limitation, so it is exercised manually, not here.
$pair = null; $p2 = null;
socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
[$a, $b] = $pair;

// iov scatter/gather round-trip
var_dump(socket_sendmsg($a, ['iov' => ['hello ', 'world', '!']], 0));
$cs = socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 1);
$r = ['buffer_size' => 64, 'controllen' => $cs, 'iov' => [null], 'name' => []];
var_dump(socket_recvmsg($b, $r, 0));
var_dump($r['iov'][0]);

// SCM_RIGHTS: pass a socket fd across; the control message arrives with one fd.
socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $p2);
[$x, $y] = $p2;
socket_sendmsg($a, ['iov' => ['F'], 'control' => [['level' => SOL_SOCKET, 'type' => SCM_RIGHTS, 'data' => [$x]]]], 0);
$r2 = ['buffer_size' => 16, 'controllen' => $cs, 'iov' => [null], 'name' => []];
var_dump(socket_recvmsg($b, $r2, 0));
$ctl = $r2['control'][0];
var_dump($ctl['level'] === SOL_SOCKET);
var_dump($ctl['type'] === SCM_RIGHTS);
var_dump(count($ctl['data']));
echo "done\n";
