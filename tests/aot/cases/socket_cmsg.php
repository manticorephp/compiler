<?php
// socket_cmsg_space host-aware alignment. The RAW values differ by host (Darwin
// cmsghdr 12/align4 -> 16,20,24; glibc 16/align8 -> 24,32,40), so assert only
// host-invariant properties here; exact per-host values are difftest-checked live.
$one = socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 1);
$two = socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 2);
var_dump(is_int($one));
var_dump($one > 0);
// ⚠ PLATFORM-DIVERGENT (hence expected/socket_cmsg.linux.out): CMSG_SPACE grows with
// the fd count on Darwin, but on Linux a 16-byte cmsghdr plus 8-byte alignment gives the
// SAME 24 bytes for one fd and for two — php reports it the same way.
var_dump($two > $one);
var_dump($one % 4 === 0);         // aligned
var_dump(SCM_RIGHTS);
echo "done\n";
