<?php
// Manticore-only (Async\ and PGSQL_ATTR_FETCH_BATCH are supersets); expectation lives in the sibling .expected file.
// A task that streams a batched portal: cancellation never leaves a half-read portal on a connection another task reuses,
// the owner gets an error for a second statement, another task waits its turn.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
function pdo(array $o = []): PDO { return new PDO((string) getenv("PDO_PG_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 1300 => 2]); }
const SLOW = 'select n, pg_sleep(0.3) from generate_series(1, 6) n';
function state(PDO $db, string $label): void {
    try { $s = $db->query('select 1'); $s->fetchAll(); echo "$label: usable\n"; }
    catch (PDOException $e) { echo "$label: PDOException ", $e->errorInfo[0], "\n"; }
}
function active(PDO $watch, int $pid): int {
    $n = -1;
    for ($i = 0; $i < 40; $i++) {
        $st = $watch->prepare("select count(*) from pg_stat_activity where pid = ? and state = 'active'");
        $st->execute([$pid]);
        $n = (int) $st->fetchColumn();
        $st = null;
        if ($n === 0) { break; }
        delay(0.05);
    }
    return $n;
}
async(function () {
    $watch = pdo();
    // A: cancelled while parked in the read of a batch
    $db = pdo();
    $q = $db->query('select pg_backend_pid()'); $pid = (int) $q->fetchColumn(); $q->closeCursor(); $q = null;
    $t = spawn(function () use ($db) {
        $s = $db->query(SLOW);
        $got = 0;
        while ($s->fetch(PDO::FETCH_NUM) !== false) { $got++; }
        return $got;
    });
    delay(0.9);
    $t->cancel();
    $t->join();
    state($db, 'A mid-read');
    echo "A active: ", active($watch, $pid), "\n";

    // B: cancelled while it holds a suspended portal and sleeps elsewhere; the destructor's Close hits the cancellation
    $db = pdo();
    $t = spawn(function () use ($db) {
        $s = $db->query(SLOW);
        $s->fetch();
        delay(5);
        return 'late';
    });
    delay(0.9);
    $t->cancel();
    $t->join();
    state($db, 'B holding');

    // C: a task ends with a suspended portal; its Close completes and the connection stays usable
    $db = pdo();
    $t = spawn(function () use ($db) {
        $s = $db->query('select n from generate_series(1, 10) n');
        $s->fetch();
        return 'done';
    });
    echo "C task: ", $t->await(), "\n";
    state($db, 'C finished');

    // D: the owner is refused, another task waits behind the portal and runs once it is closed
    $db = pdo();
    $log = [];
    $a = spawn(function () use ($db, &$log) {
        $s = $db->query('select n, pg_sleep(0.1) from generate_series(1, 5) n');
        $log[] = 'a open';
        try { $db->query('select 99'); $log[] = 'a second query ok'; }
        catch (PDOException $e) { $log[] = 'a second query refused'; }
        while ($s->fetch(PDO::FETCH_NUM) !== false) { }
        $log[] = 'a done';
        return 1;
    });
    $b = spawn(function () use ($db, &$log) {
        delay(0.05);
        $log[] = 'b asks';
        $q = $db->query('select 7');
        $log[] = 'b got ' . $q->fetchColumn();
        return 1;
    });
    awaitAll($a, $b);
    echo implode("\n", $log), "\n";
});
