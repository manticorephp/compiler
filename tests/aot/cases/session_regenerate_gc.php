<?php

// session_regenerate_id() and session_gc() over the files store.
//
// The stale records are back-dated with touch() rather than waited for, which is
// what makes the collection deterministic; the live session has no file yet
// (the record is written at close), so gc must leave exactly the two.
$dir = sys_get_temp_dir() . "/mc_sess_rgc";
if (!is_dir($dir)) {
    mkdir($dir);
}
foreach (["stale1", "stale2"] as $s) {
    file_put_contents($dir . "/sess_" . $s, "v|i:1;");
    touch($dir . "/sess_" . $s, time() - 100000);
}
ini_set("session.save_path", $dir);
ini_set("session.use_cookies", "0");
ini_set("session.gc_maxlifetime", "3600");
ini_set("session.gc_probability", "0");
session_id("regenone");

$out = "";
$out .= var_export(session_start(), true) . "\n";
$_SESSION["v"] = 1;
$old = session_id();
$out .= var_export(session_gc(), true) . "\n";
$out .= var_export(file_exists($dir . "/sess_stale1"), true) . "\n";
$out .= var_export(file_exists($dir . "/sess_stale2"), true) . "\n";

$out .= var_export(session_regenerate_id(false), true) . "\n";
$new = session_id();
$out .= var_export($old !== $new, true) . "\n";
$out .= var_export(strlen($new), true) . "\n";
$out .= var_export($_SESSION["v"], true) . "\n";
$out .= var_export(session_write_close(), true) . "\n";
$out .= var_export(file_exists($dir . "/sess_" . $new), true) . "\n";
$out .= var_export(file_exists($dir . "/sess_" . $old), true) . "\n";

unlink($dir . "/sess_" . $new);
unlink($dir . "/sess_" . $old);
$out .= var_export(count(glob($dir . "/*")), true) . "\n";

echo $out;
rmdir($dir);
