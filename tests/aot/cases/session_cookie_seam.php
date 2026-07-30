<?php

// The session over the request seam: the id travels in the cookie, so a second
// request carrying it reads the first request's data back.
//
// No php oracle — php's CLI has no request to begin — but the Set-Cookie line
// is the same one php's SAPI emits, and it is what a browser has to accept.
$dir = sys_get_temp_dir() . "/mc_sess_seam";
if (!is_dir($dir)) {
    mkdir($dir);
}
ini_set("session.save_path", $dir);
ini_set("session.cookie_httponly", "1");
ini_set("session.cookie_samesite", "Lax");

// Request 1: no cookie, so the session is new and its cookie goes out.
__mc_request_begin(["REQUEST_URI" => "/"], [], [], []);
session_start();
$_SESSION["hits"] = 1;
$id = session_id();
$hdrs = __mc_response_headers();
echo "headers=", count($hdrs), "\n";
$cookie = "";
foreach ($hdrs as $h) {
    if (str_starts_with($h, "Set-Cookie: ")) {
        $cookie = $h;
    }
}
echo "cookie=", str_replace($id, "<ID>", $cookie), "\n";
echo "idlen=", strlen($id), "\n";
session_write_close();
__mc_request_end();

// Request 2: the browser sends it back, so the same record is read.
__mc_request_begin(["REQUEST_URI" => "/"], [], [], ["PHPSESSID" => $id]);
session_start();
echo "same-id=", session_id() === $id ? "yes" : "NO", "\n";
echo "hits=", $_SESSION["hits"], "\n";
$_SESSION["hits"] = 2;
$again = "";
foreach (__mc_response_headers() as $h) {
    if (str_starts_with($h, "Set-Cookie: ")) {
        $again = "resent";
    }
}
echo "cookie-again=", $again === "" ? "no" : $again, "\n";
session_write_close();
__mc_request_end();

// Request 3: still there, and the second request's write is what it reads.
__mc_request_begin(["REQUEST_URI" => "/"], [], [], ["PHPSESSID" => $id]);
session_start();
echo "hits=", $_SESSION["hits"], "\n";
session_destroy();
__mc_request_end();

echo "left=", count(glob($dir . "/*")), "\n";
rmdir($dir);
