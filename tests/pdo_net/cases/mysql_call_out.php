<?php
// A server-prepared CALL with an OUT parameter: the OUT-parameter result set (SERVER_PS_OUT_PARAMS) is hidden like
// mysqlnd does, buffered and unbuffered, with and without a result set of the procedure's own; the link stays in sync.
$dsn = getenv('PDO_MY_DSN');
$db = new PDO($dsn, 'u', 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$db->exec('DROP PROCEDURE IF EXISTS pdo_call_out1');
$db->exec('DROP PROCEDURE IF EXISTS pdo_call_out2');
$db->exec('CREATE PROCEDURE pdo_call_out1(IN a INT, OUT b INT) BEGIN SET b = a * 2; END');
$db->exec('CREATE PROCEDURE pdo_call_out2(IN a INT, OUT b INT) BEGIN SET b = a * 2; SELECT a AS x, a + 1 AS y; END');
foreach ([true, false] as $buf) {
    $db->setAttribute(1000, $buf); // Pdo\Mysql::ATTR_USE_BUFFERED_QUERY (the PDO:: spelling is deprecated)
    echo "== buffered ", json_encode($buf), "\n";
    foreach (['pdo_call_out1', 'pdo_call_out2'] as $p) {
        $st = $db->prepare("CALL $p(?, ?)");
        $st->execute([21, 0]);
        $set = 0;
        do {
            echo "$p set ", $set++, " cols=", $st->columnCount(), " ", json_encode($st->fetchAll(PDO::FETCH_ASSOC)), "\n";
        } while ($st->nextRowset());
        $st->closeCursor();
        echo json_encode($db->query('SELECT 7 AS z')->fetchAll(PDO::FETCH_ASSOC)), "\n";
    }
}
$db->exec('DROP PROCEDURE pdo_call_out1');
$db->exec('DROP PROCEDURE pdo_call_out2');
