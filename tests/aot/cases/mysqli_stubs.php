<?php
// mysqli stubs and stats that need no server: debug, dump_debug_info, embedded server (gone in 8.5), the mysqlnd stats key set, thread_safe, client info.
mysqli_report(MYSQLI_REPORT_OFF);
var_dump(mysqli_debug('d:t'));
$s = mysqli_get_client_stats();
echo count($s), ' ', md5(implode(',', array_keys($s))), "\n";
var_dump(array_unique(array_values($s)));
$m = new mysqli();
var_dump($m->debug('x'));
foreach (['dump_debug_info', 'get_connection_stats'] as $f) {
    try { var_dump($m->$f()); } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}
try { var_dump(mysqli_get_connection_stats($m)); } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { var_dump(mysqli_dump_debug_info($m)); } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
var_dump(method_exists('mysqli_driver', 'embedded_server_start'), method_exists('mysqli_driver', 'embedded_server_end'));
var_dump(mysqli_get_links_stats());
var_dump(mysqli_thread_safe(), $m->thread_safe(), mysqli_get_client_version() === PHP_VERSION_ID);
var_dump(str_starts_with(mysqli_get_client_info(), 'mysqlnd '));
var_dump(@constant('MYSQLI_IS_MARIADB'));
