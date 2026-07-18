<?php
var_dump(ip2long('192.168.1.1'));
var_dump(ip2long('255.255.255.255'));
var_dump(ip2long('1.2.3.256'));
var_dump(ip2long('1.2.3'));
var_dump(ip2long('1.2.3.04'));
var_dump(long2ip(3232235777));
var_dump(long2ip(ip2long('10.0.0.1')));
var_dump(bin2hex(inet_pton('1.2.3.4')));
var_dump(inet_ntop(inet_pton('8.8.8.8')));
var_dump(bin2hex(inet_pton('::1')));
var_dump(inet_ntop(inet_pton('2001:db8::1')));
var_dump(inet_pton('not-an-ip'));
var_dump(inet_ntop('xx'));
var_dump(gethostbyname('127.0.0.1'));
var_dump(strlen(gethostname()) > 0);
