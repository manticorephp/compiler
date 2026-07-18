<?php
$e=0;$m='';
$fp = @pfsockopen('127.0.0.1', 1, $e, $m, 2);
var_dump(@gethostbyaddr('not.an.ip'));
var_dump(strlen(gethostbyaddr('127.0.0.1')) > 0);
var_dump(stream_isatty(STDOUT));
var_dump($fp === false && $e !== 0);
var_dump(in_array('tcp', stream_get_transports(), true));
var_dump(count(stream_get_wrappers()) > 0);
echo "done\n";
