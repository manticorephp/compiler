<?php
// max_prepared_stmt_count (GLOBAL): runs on the throwaway server named by the sibling .dsn (mc-my-iso), never the shared one.
// The case sets the limit to 5 itself, as root, and restores it on any exit.
$dsn = (string) getenv('PDO_MY_DSN');
if (!str_contains($dsn, 'port=33065')) { echo "ASSERTION FAILED: this case must run on the isolated server\n"; exit(1); }
$admin = new PDO($dsn, 'root', 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$qa = $admin->query('select @@max_prepared_stmt_count');
$orig = (int) $qa->fetchColumn();
$qa = null;
register_shutdown_function(function () use ($admin, $orig) { $admin->exec('set global max_prepared_stmt_count = ' . $orig); });
$admin->exec('set global max_prepared_stmt_count = 5');
$seen = 0;
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_EXCEPTION] as $mode) {
    echo "== errmode $mode\n";
    $db = new PDO($dsn, getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => $mode, PDO::ATTR_EMULATE_PREPARES => false]);
    $held = [];
    for ($i = 0; $i < 8; $i++) {
        try {
            $s = $db->prepare('select ' . $i);
            if ($s === false) { echo "prepare $i => false ", json_encode($db->errorInfo()), "\n"; if ($db->errorInfo()[1] === 1461) { $seen++; } continue; }
            $held[] = $s;
            echo "prepare $i => ok\n";
        } catch (PDOException $e) {
            echo "prepare $i => EXC ", $e->getMessage(), ' ', json_encode($e->errorInfo), "\n";
            if (($e->errorInfo[1] ?? 0) === 1461) { $seen++; }
        }
    }
    foreach ($held as $i => $s) { $s->execute(); echo $s->fetchColumn(), ' '; }
    echo "\n";
    $held[0] = null;
    $s = $db->prepare('select 99');
    echo $s === false ? "still full\n" : "slot reused\n";
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $e = $db->prepare('select 100');
    $e->execute();
    echo $e->fetchColumn(), "\n";
    $held = [];
    $s = null;
    $e = null;
}
// 3 refusals per errmode: a case that never saw error 1461 proved nothing
if ($seen !== 6) { echo "ASSERTION FAILED: error 1461 observed $seen times, expected 6\n"; }
else { echo "1461 observed 6 times\n"; }
