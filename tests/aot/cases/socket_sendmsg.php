<?php
// socket_sendmsg/recvmsg iov (scatter/gather) round-trip over a socketpair —
// offline + deterministic. #[CellArg] on $message cellifies the iov sub-array so
// the once-compiled stdlib reads it as tagged cells. (Ancillary fd-passing via
// 'control' needs deep heterogeneous cellify — see the WIP note; not tested here.)
$pair = null;
socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
[$a, $b] = $pair;

$sent = socket_sendmsg($a, ['iov' => ['hello ', 'world', '!']], 0);
var_dump($sent);

$r = ['buffer_size' => 64, 'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 1), 'iov' => [null], 'name' => []];
$got = socket_recvmsg($b, $r, 0);
var_dump($got);
var_dump($r['iov'][0]);
var_dump($r['flags']);
echo "done\n";
