<?php
// Stats and debug stubs on a live link: the connection stats carry mysqlnd's key set, debug / dump_debug_info answer true, a closed link refuses.
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
$s = mysqli_get_connection_stats($m);
echo count($s), ' ', md5(implode(',', array_keys($s))), ' ', json_encode(array_keys($s) === array_keys($m->get_connection_stats())), "\n";
echo json_encode(array_unique(array_map('gettype', $s))), "\n";
var_dump($m->debug('d:t'), $m->dump_debug_info(), mysqli_dump_debug_info($m));
$m->close();
foreach (['dump_debug_info', 'get_connection_stats'] as $f) {
    try { var_dump($m->$f()); } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}
var_dump($m->debug('x'));
