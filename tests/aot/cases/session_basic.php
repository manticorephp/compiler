<?php

// A session end to end over the built-in files store: start, write, close,
// re-open the same id, read it back, destroy.
//
// ⚠ Nothing prints before the last loop, and that is not style: php's CLI marks
// the headers sent at the first byte of output, after which session_start() and
// every ini_set('session.*') FAIL. A case that printed as it went would be
// asserting the frozen path.
$dir = sys_get_temp_dir() . "/mc_sess_basic";
if (!is_dir($dir)) {
    mkdir($dir);
}
ini_set("session.save_path", $dir);
ini_set("session.use_cookies", "0");
session_id("mcbasic1");

$r = [];
$r[] = session_status();
$r[] = session_start();
$r[] = session_status();
$r[] = session_id();
$r[] = session_name();

$_SESSION["n"] = 1;
$_SESSION["s"] = "abc";
$_SESSION["a"] = [1, 2];
$_SESSION["b"] = true;
$_SESSION["f"] = 1.5;
$_SESSION["z"] = null;
$r[] = session_encode();
$r[] = session_write_close();
$r[] = session_status();
$r[] = file_get_contents($dir . "/sess_mcbasic1");

// Same id again: the record comes back through the store, not from memory.
$_SESSION = [];
$r[] = session_start();
$r[] = $_SESSION["n"];
$r[] = $_SESSION["s"];
$r[] = count($_SESSION["a"]);
$r[] = $_SESSION["a"][1];
$r[] = $_SESSION["b"];
$r[] = $_SESSION["f"];
$r[] = $_SESSION["z"];
$r[] = isset($_SESSION["nope"]);

$r[] = session_destroy();
$r[] = session_status();
$r[] = file_exists($dir . "/sess_mcbasic1");

foreach ($r as $v) {
    var_dump($v);
}
rmdir($dir);
