<?php
// Manticore-only (sleeps 31 s: past the 30 s default timeout); expectation in the sibling .expected file.
// Several statements in one exec(): the first has answered, the second keeps running; the default timeout must not cut it off.
$db = new PDO((string) getenv("PDO_MY_DSN"), "u", "pw", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$t0 = microtime(true);
$db->exec('DO 1; DO SLEEP(31)');
echo microtime(true) - $t0 > 30.0 ? "waited\n" : "too fast\n";
echo $db->query('select 1')->fetchColumn(), "\n";
