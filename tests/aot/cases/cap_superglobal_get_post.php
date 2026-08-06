<?php
// @epic: sapi-layer
// @why: Request::createFromGlobals() reads all of these. LowerSuperglobals.php
//       declares them but superglobalInit() seeds only $_SERVER and $_ENV, so
//       every request-scoped superglobal is permanently [].

var_dump($_GET);
var_dump($_POST);
var_dump($_COOKIE);
var_dump($_FILES);
var_dump($_REQUEST);

// Writable and visible across scopes is the part that already works — this
// pins that half so a SAPI implementation cannot regress it.
$_GET['page'] = '2';
function capReadGet(): string { return $_GET['page'] ?? 'unset'; }
var_dump(capReadGet());
var_dump($_GET['missing'] ?? 'default');
