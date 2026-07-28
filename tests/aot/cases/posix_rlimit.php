<?php
// posix_getrlimit / posix_setrlimit — php IS the oracle here, so everything
// printed must be host-stable: the resource NUMBERS diverge (NOFILE is 8 on
// Darwin, 7 on Linux), and so do the actual limits, which is why nothing below
// prints a hard limit it did not set itself.

// NOT compared against PHP_INT_MAX: php hands out the host RLIM_INFINITY, which is
// PHP_INT_MAX on Darwin and -1 on glibc. What is portable is that it round-trips.
var_dump(is_int(POSIX_RLIMIT_INFINITY));

// Lowering a soft limit needs no privilege anywhere. Round-trip it.
$before = posix_getrlimit(POSIX_RLIMIT_NOFILE);
var_dump(posix_setrlimit(POSIX_RLIMIT_NOFILE, 64, $before[1] === 'unlimited' ? PHP_INT_MAX : $before[1]));
$after = posix_getrlimit(POSIX_RLIMIT_NOFILE);
var_dump($after[0], count($after));

// A core dump of size 0 is the one limit whose exact value is portable.
var_dump(posix_setrlimit(POSIX_RLIMIT_CORE, 0, 0));
var_dump(posix_getrlimit(POSIX_RLIMIT_CORE));

// "no limit" survives the round trip as the string php uses.
var_dump(posix_setrlimit(POSIX_RLIMIT_CPU, PHP_INT_MAX, PHP_INT_MAX));
var_dump(posix_getrlimit(POSIX_RLIMIT_CPU)[0]);

// The no-argument form: names, not numbers. Only the keys both hosts have.
$all = posix_getrlimit();
var_dump(isset($all['soft openfiles']), isset($all['hard core']), $all['soft core']);

// A resource that does not exist: php returns false and does NOT warn.
var_dump(posix_setrlimit(9999, 10, 10));
