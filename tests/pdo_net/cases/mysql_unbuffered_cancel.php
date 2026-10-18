<?php
// Manticore-only (Async\ is a superset); expectation lives in the sibling .expected file.
// Cancelling a task that streams an unbuffered result never leaves half a result on a connection another task reuses.
use function Async\async;
use function Async\spawn;
use function Async\delay;
function pdo(array $o = []): PDO { return new PDO((string) getenv("PDO_MY_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 1000 => false]); }
const SLOW = 'select n, sleep(0.3) from (select 1 n union all select 2 union all select 3 union all select 4 union all select 5) x';
function state(PDO $db, string $label): void {
    try { $s = $db->query('select 1'); $s->fetchAll(); echo "$label: usable\n"; }
    catch (PDOException $e) { echo "$label: PDOException ", $e->errorInfo[1], "\n"; }
}
function running(int $id): int {
    $fresh = pdo();
    $n = -1;
    for ($i = 0; $i < 40; $i++) {
        $st = $fresh->prepare("select count(*) from information_schema.processlist where id = ? and command = 'Query'");
        $st->execute([$id]);
        $n = (int) $st->fetchColumn();
        $st = null;
        if ($n === 0) { break; }
        delay(0.05);
    }
    return $n;
}
async(function () {
    // A: cancelled while parked inside the read of the next row
    $db = pdo();
    $q = $db->query('select connection_id()'); $id = (int) $q->fetchColumn(); $q->closeCursor(); $q = null;
    $t = spawn(function () use ($db) {
        $s = $db->query(SLOW);
        $got = 0;
        while ($s->fetch(PDO::FETCH_NUM) !== false) { $got++; }
        return $got;
    });
    delay(0.5);
    $t->cancel();
    $t->join();
    state($db, 'A mid-read');
    echo "A killed: ", running($id), "\n";

    // B: cancelled while holding a half-read cursor and parked elsewhere; the destructor's drain hits the cancellation
    $db = pdo();
    $q = $db->query('select connection_id()'); $id = (int) $q->fetchColumn(); $q->closeCursor(); $q = null;
    $t = spawn(function () use ($db) {
        $s = $db->query(SLOW);
        $s->fetch();
        delay(5);
        return 'late';
    });
    delay(0.45);
    $t->cancel();
    $t->join();
    state($db, 'B holding');
    echo "B killed: ", running($id), "\n";

    // C: a task ends with a small cursor still open; its drain completes and the connection stays usable
    $db = pdo();
    $t = spawn(function () use ($db) {
        $s = $db->query('select 1 union select 2 union select 3');
        $s->fetch();
        return 'done';
    });
    echo "C task: ", $t->await(), "\n";
    state($db, 'C finished');

    // D: statement closed explicitly while another task waits on the busy connection
    $db = pdo();
    $s = $db->query(SLOW);
    $s->fetch();
    $w = spawn(function () use ($db) { $q = $db->query('select 42'); return $q->fetchColumn(); });
    delay(0.1);
    $s->closeCursor();
    echo "D waiter: ", $w->await(), "\n";
    state($db, 'D after');
});
