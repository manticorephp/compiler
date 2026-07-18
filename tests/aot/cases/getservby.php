<?php
var_dump(getservbyname('http', 'tcp'));
var_dump(getservbyname('https', 'tcp'));
var_dump(getservbyname('nope-service', 'tcp'));
var_dump(getprotobyname('tcp'));
var_dump(getprotobyname('udp'));
var_dump(getprotobynumber(6));
var_dump(getprotobyname('nope-proto'));
var_dump(strlen(getservbyport(80, 'tcp')) > 0);
var_dump(openlog('mc', 0, 8) && syslog(3, 'test %s') && closelog());
