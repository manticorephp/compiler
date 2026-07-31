<?php

// session.serialize_handler = php_serialize, written and read back through the store, so
// the on-disk bytes are what the next start decodes.
//
// ⚠ Two constraints shape this file. php's CLI marks the headers sent at the
// first byte of output AND at the cache-limiter headers session_start() emits,
// so everything is configured once, up front, and nothing prints until the end.
// The results accumulate into a STRING rather than an array because a
// heterogeneous collection array erases its element type here.
$dir = sys_get_temp_dir() . "/mc_sess_phpser";
if (!is_dir($dir)) {
    mkdir($dir);
}
ini_set("session.save_path", $dir);
ini_set("session.use_cookies", "0");
ini_set("session.serialize_handler", "php_serialize");
session_id("encphpser");

$out = "";
$out .= var_export(session_start(), true) . "\n";
$_SESSION["i"] = 42;
$_SESSION["str"] = "a|b";
$_SESSION["arr"] = ["x" => 1];
$out .= var_export(session_encode(), true) . "\n";
$out .= var_export(session_write_close(), true) . "\n";
$out .= var_export(file_get_contents($dir . "/sess_encphpser"), true) . "\n";

$_SESSION = [];
$out .= var_export(session_start(), true) . "\n";
$out .= var_export($_SESSION["i"], true) . "\n";
$out .= var_export($_SESSION["str"], true) . "\n";
$out .= var_export($_SESSION["arr"]["x"], true) . "\n";
$out .= var_export(session_destroy(), true) . "\n";
$out .= var_export(file_exists($dir . "/sess_encphpser"), true) . "\n";

echo $out;
rmdir($dir);