<?php
// socket_cmsg_space host-aware alignment. The RAW values differ by host (Darwin
// cmsghdr 12/align4 -> 16,20,24; glibc 16/align8 -> 24,32,40), so assert only
// host-invariant properties here; exact per-host values are difftest-checked live.
$one = socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 1);
$two = socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 2);
var_dump(is_int($one));
var_dump($one > 0);
var_dump($two > $one);            // more fds -> more space
var_dump($one % 4 === 0);         // aligned
var_dump(SCM_RIGHTS);
echo "done\n";
