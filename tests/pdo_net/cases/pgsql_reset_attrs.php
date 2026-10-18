<?php
// Manticore-only (superset resetSession); expectation lives in the sibling .expected file.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
$dsn = (string) getenv("PDO_PG_DSN");
$base = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 7, PDO::PGSQL_ATTR_FETCH_BATCH => 3];
function show(PDO $db): string {
    return implode(' ', [$db->getAttribute(PDO::ATTR_ERRMODE), $db->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE), $db->getAttribute(PDO::ATTR_CASE),
        var_export($db->getAttribute(PDO::ATTR_STRINGIFY_FETCHES), true), $db->getAttribute(PDO::PGSQL_ATTR_FETCH_BATCH), var_export($db->getAttribute(PDO::PGSQL_ATTR_DISABLE_PREPARES), true)]);
}
async(function () use ($dsn, $base) {
    $db = new PDO($dsn, "u", "pw", $base);
    echo "start: ", show($db), "\n";
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_NUM);
    $db->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
    $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    $db->setAttribute(PDO::PGSQL_ATTR_FETCH_BATCH, 0);
    $db->setAttribute(PDO::PGSQL_ATTR_DISABLE_PREPARES, true);
    echo "changed: ", show($db), "\n";
    $db->query('select nonexistent');
    echo "errState before: ", $db->errorCode(), "\n";
    $db->exec('LISTEN rst');
    $db->exec("NOTIFY rst, 'x'");
    $db->resetSession();
    echo "reset: ", show($db), "\n";
    echo "errState after: ", $db->errorCode(), "\n";
    echo "notify queue: ", var_export($db->getNotify(PDO::FETCH_ASSOC, 0), true), "\n";
    $r = $db->query('select 1 as A')->fetch();
    echo "row: ", json_encode($r), "\n";
    try { $db->query('select nonexistent'); echo "no throw\n"; } catch (PDOException $e) { echo "throws again\n"; }

    // a reset by a fiber that does not own the open cursor is refused
    $db = new PDO($dsn, "u", "pw", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::PGSQL_ATTR_FETCH_BATCH => 2]);
    $st = $db->query('select generate_series(1, 10)');
    $st->fetch();
    $t = spawn(function () use ($db) {
        try { $db->resetSession(); echo "foreign reset: MISSING\n"; } catch (PDOException $e) { echo "foreign reset refused\n"; }
    });
    awaitAll($t);
    echo "owner still reads: ", $st->fetch(PDO::FETCH_COLUMN), "\n";
    $st->closeCursor();
    $db->resetSession();
    echo "owner reset ok\n";
});
