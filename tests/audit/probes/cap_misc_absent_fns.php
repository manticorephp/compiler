<?php
// @epic: stdlib-leaf
// @why: the tail of the absent-function list found on the green corpus. Each is
//       reached from a live symfony path: Process::getDefaultEnv/phpinfo
//       detection, Command::setProcessTitle, and composer's ClassLoader
//       include-path lookup. Low individual value, grouped so none is forgotten.

var_dump(stream_resolve_include_path('nonexistent-cap-file.php'));
var_dump(stream_resolve_include_path(__FILE__) === __FILE__);

// Zend accepts any string; the return is a plain bool.
var_dump(cli_set_process_title('cap-probe'));

// phpinfo() writes to output; INFO_GENERAL alone keeps it small. Only its
// PRESENCE and return value are compared -- the body is host-specific, so it is
// captured into a buffer and discarded.
ob_start();
$ok = phpinfo(INFO_GENERAL);
ob_end_clean();
var_dump($ok);
