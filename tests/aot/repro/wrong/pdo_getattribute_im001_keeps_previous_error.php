<?php
// PDO::getAttribute() of an unsupported attribute resets errorInfo code and message instead of keeping the previous ones
// issue: #327
set_error_handler(function ($n, $s) { return true; });
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
$db->exec('selec');
$db->getAttribute(9999);
echo json_encode($db->errorInfo()), "\n";
