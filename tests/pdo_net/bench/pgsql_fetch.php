<?php
// Throughput bench: same script under php (libpq) and compiled. Prints `name <ms>`.
$dsn = getenv("PDO_PG_DSN") ?: "pgsql:host=127.0.0.1;port=54320;dbname=d";
$db = new PDO($dsn, "u", "pw", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function ms(int $t0): string { return sprintf("%.1f", (hrtime(true) - $t0) / 1e6); }
$q = "select g, 'name' || g, g * 1.5, g % 2 = 0, null from generate_series(1, 200000) g";

$t = hrtime(true);
$r = $db->query($q)->fetchAll(PDO::FETCH_NUM);
echo "fetch_num ", ms($t), "\n";
if (count($r) !== 200000) { echo "bad count\n"; }
unset($r);

$t = hrtime(true);
$r = $db->query($q)->fetchAll(PDO::FETCH_ASSOC);
echo "fetch_assoc ", ms($t), "\n";
unset($r);

$s = $db->prepare("select ?::int + 1, 'x'");
$t = hrtime(true);
for ($i = 0; $i < 20000; $i++) { $s->execute([$i]); $s->fetch(PDO::FETCH_NUM); $s->closeCursor(); }
echo "prepared_20k ", ms($t), "\n";

$db->exec("create temp table b (id int, name text, v float8)");
$ins = $db->prepare("insert into b values (?, ?, ?)");
$t = hrtime(true);
$db->beginTransaction();
for ($i = 0; $i < 20000; $i++) { $ins->execute([$i, "n$i", $i * 1.5]); }
$db->commit();
echo "insert_20k ", ms($t), "\n";
