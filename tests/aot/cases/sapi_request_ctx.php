<?php

// The request seam an HTTP server drives: seed the GPC superglobals, build a
// response, drain it. No php oracle — php's CLI has no request to begin — but
// the Set-Cookie lines below are byte-identical to what php's own SAPI emits
// (captured from php -S), which is the part a browser has to accept.
__mc_request_begin(
    ["REQUEST_URI" => "/x?a=1", "REQUEST_METHOD" => "POST"],
    ["a" => "1"],
    ["b" => "2"],
    ["PHPSESSID" => "abc123"]
);

echo $_GET["a"], " ", $_POST["b"], " ", $_COOKIE["PHPSESSID"], "\n";
echo $_REQUEST["a"], $_REQUEST["b"], "\n";
echo $_SERVER["REQUEST_URI"], " ", $_SERVER["REQUEST_METHOD"], "\n";
// The CLI keys survive the merge — a framework still sees where it runs.
echo isset($_SERVER["SCRIPT_NAME"]) ? "script-name-kept" : "SCRIPT-NAME-LOST", "\n";

header("Content-Type: text/plain");
header("X-A: 1");
header("X-A: 2");
header("X-A: 3", false);
header("X-Gone: 1");
header_remove("X-Gone");
// An expiry in the past clamps Max-Age to 0, which keeps the line deterministic.
setcookie("s", "v", ["expires" => 1000000000, "path" => "/", "httponly" => true, "samesite" => "Lax"]);
setcookie("del", "");
setcookie("enc", "a b/c=d&e");
setrawcookie("raw", "a-b_c.d");
http_response_code(418);

echo count(headers_list()), " queued\n";
var_dump(http_response_code());

echo "status=", __mc_response_status(), "\n";
foreach (__mc_response_headers() as $h) {
    echo $h, "\n";
}
__mc_request_end();
// With no request in flight the CLI answers are back.
var_dump(headers_list(), http_response_code());
