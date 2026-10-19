<?php
// Manticore-only (sleeps 31 s: past the 30 s default timeout); expectation in the sibling .expected file.
// A statement that has already answered (a NOTICE) and then keeps running must not be cut off by the default timeout.
$db = new PDO((string) getenv("PDO_PG_DSN"), "u", "pw", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$t0 = microtime(true);
$db->exec("DO \$\$BEGIN RAISE NOTICE 'x'; PERFORM pg_sleep(31); END\$\$");
echo microtime(true) - $t0 > 30.0 ? "waited\n" : "too fast\n";
echo $db->query('select 1')->fetchColumn(), "\n";
