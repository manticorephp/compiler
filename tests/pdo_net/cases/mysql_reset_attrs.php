<?php
// Manticore-only (superset resetSession); expectation lives in the sibling .expected file.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
$dsn = (string) getenv("PDO_MY_DSN");
$base = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 7,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, PDO::ATTR_EMULATE_PREPARES => false];
function show(PDO $db): string {
    return implode(' ', [$db->getAttribute(PDO::ATTR_ERRMODE), $db->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE), $db->getAttribute(PDO::ATTR_CASE),
        var_export($db->getAttribute(PDO::ATTR_STRINGIFY_FETCHES), true), var_export($db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY), true),
        var_export($db->getAttribute(PDO::ATTR_EMULATE_PREPARES), true)]);
}
async(function () use ($dsn, $base) {
    $db = new PDO($dsn, "u", "pw", $base);
    echo "start: ", show($db), "\n";
    $coll = $db->query('select @@collation_connection')->fetchAll(PDO::FETCH_COLUMN)[0];
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_NUM);
    $db->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
    $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    echo "changed: ", show($db), "\n";
    $db->query('select nonexistent');
    echo "errState before: ", $db->errorCode(), "\n";
    $db->resetSession();
    echo "reset: ", show($db), "\n";
    echo "errState after: ", $db->errorCode(), "\n";
    echo "collation kept: ", $db->query('select @@collation_connection')->fetchAll(PDO::FETCH_COLUMN)[0] === $coll ? 'yes' : 'NO', "\n";
    echo "row: ", json_encode($db->query('select 1 as a')->fetch()), "\n";
    try { $db->query('select nonexistent'); echo "no throw\n"; } catch (PDOException $e) { echo "throws again\n"; }

    // a reset by a fiber that does not own the open cursor is refused
    $db = new PDO($dsn, "u", "pw", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]);
    $st = $db->query('select 1 union all select 2 union all select 3');
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
