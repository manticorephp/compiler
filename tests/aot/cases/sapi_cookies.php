<?php

// header/setcookie under a plain CLI run, where php has no header block to send:
// the calls succeed, headers_list() stays empty and http_response_code() is false.
// Every assertion here holds 1:1 under `php`, so difftest covers it.
//
// ⚠ Nothing prints before the calls, and that is not style: php's CLI marks the
// headers sent at the first byte of output, and every header call after that
// fails. A case that printed first would assert the frozen path instead.
$r = [];
$r[] = headers_sent();
header("X-A: 1");
$r[] = setcookie("plain", "v");
$r[] = setcookie("full", "v", ["expires" => 2000000000, "path" => "/p", "samesite" => "Lax"]);
$r[] = setcookie("legacy", "v", 2000000000, "/l", "l.com", true, true);
$r[] = setrawcookie("raw", "a-b_c.d");
$r[] = headers_list();
$r[] = http_response_code();
$r[] = setcookie("enc", "a b/c=d&e");
header_remove("X-A");
header_remove();

$errs = [];
try {
    setcookie("bad;name", "v");
} catch (\Throwable $e) {
    $errs[] = get_class($e) . ": " . $e->getMessage();
}
try {
    setcookie("bad=name", "v");
} catch (\Throwable $e) {
    $errs[] = get_class($e) . ": " . $e->getMessage();
}
try {
    setrawcookie("r", "a b");
} catch (\Throwable $e) {
    $errs[] = get_class($e) . ": " . $e->getMessage();
}
try {
    setcookie("c", "v", ["nope" => 1]);
} catch (\Throwable $e) {
    $errs[] = get_class($e) . ": " . $e->getMessage();
}

foreach ($r as $v) {
    var_dump($v);
}
foreach ($errs as $e) {
    echo $e, "\n";
}
