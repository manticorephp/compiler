<?php
// A refused setAttribute is a plain false; getAttribute / nextRowset IM001 warn even under ERRMODE_SILENT,
// record no driver message, and PDO's public surface carries no internals. Graded against php.
set_error_handler(function ($n, $s) { echo "WARN: $s\n"; return true; });
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING, PDO::ERRMODE_EXCEPTION] as $m) {
    echo "== $m\n";
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => $m]);
    foreach ([[PDO::ATTR_AUTOCOMMIT, 1], [PDO::ATTR_TIMEOUT, 5], [9999, 1], [PDO::ATTR_PREFETCH, 1]] as [$a, $v]) {
        try { echo "set $a: ", var_export($db->setAttribute($a, $v), true), ' ', json_encode($db->errorInfo()), "\n"; } catch (PDOException $e) { echo "set $a EX ", $e->getMessage(), "\n"; }
    }
    foreach ([PDO::ATTR_AUTOCOMMIT, PDO::ATTR_PREFETCH, PDO::ATTR_TIMEOUT, 9999, PDO::ATTR_CONNECTION_STATUS] as $a) {
        try { echo "get $a: ", var_export($db->getAttribute($a), true), ' ', json_encode($db->errorInfo()), "\n"; } catch (PDOException $e) { echo "get $a EX ", $e->getMessage(), "\n"; }
    }
    $st = $db->query('select 1');
    try { echo "nextRowset: ", var_export($st->nextRowset(), true), ' ', json_encode($st->errorInfo()), "\n"; } catch (PDOException $e) { echo "EX ", $e->getMessage(), "\n"; }
    try { echo "st set: ", var_export($st->setAttribute(1, 1), true), "\n"; } catch (PDOException $e) { echo "EX ", $e->getMessage(), "\n"; }
    try { echo "st get: ", var_export($st->getAttribute(1), true), "\n"; } catch (PDOException $e) { echo "EX ", $e->getMessage(), "\n"; }
}
// query() that threw leaves no label behind
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try { $db->query('selec 1'); } catch (PDOException $e) { echo "threw\n"; }
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
$db->exec('selec 2');
