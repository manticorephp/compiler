<?php
// Unbuffered cursor over 300000 rows keeps memory flat; the buffered run of the same query does not; closing early drains.
function mk(bool $emu, bool $buffered): PDO { return new PDO((string) getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 1000 => $buffered, PDO::ATTR_EMULATE_PREPARES => $emu]); }
$sql = "with d as (select 0 n union all select 1 union all select 2 union all select 3 union all select 4 union all select 5 union all select 6 union all select 7 union all select 8 union all select 9) "
    . "select a.n+10*b.n+100*c.n+1000*e.n+10000*f.n+100000*g.n as k, repeat('x',64) as pad from d a, d b, d c, d e, d f, d g where g.n < 3";
foreach ([true, false] as $emu) {
    echo "== emulate=", var_export($emu, true), "\n";
    $db = mk($emu, false);
    $q = $db->query('select 1'); $q->fetchAll(); $q = null;
    $base = memory_get_peak_usage();
    $s = $db->query($sql);
    $n = 0; $sum = 0; $len = 0;
    while (($r = $s->fetch(PDO::FETCH_NUM)) !== false) { $n++; $sum += (int) $r[0]; $len += strlen($r[1]); }
    echo "rows=$n sum=$sum len=$len\n";
    echo "unbuffered: ", memory_get_peak_usage() - $base < 16 * 1024 * 1024 ? 'flat' : 'grew', "\n";
    $s = null;
    // partial fetch, then close: the rest is drained, the connection is reusable
    $s = $db->query($sql);
    for ($i = 0; $i < 10; $i++) { $s->fetch(); }
    $s->closeCursor();
    $c = $db->query('select 5'); echo "after closeCursor: ", $c->fetchColumn(), "\n"; $c->closeCursor(); $c = null;
    $s = $db->query($sql);
    $s->fetch();
    $s = null;
    $c = $db->query('select 6'); echo "after destruct: ", $c->fetchColumn(), "\n"; $c->closeCursor(); $c = null;
    // fetchAll of the whole result in one go works too
    $s = $db->query('select k from (' . $sql . ') t where k < 5 order by k');
    echo "fetchAll: ", json_encode($s->fetchAll(PDO::FETCH_COLUMN)), "\n";
    if (!$emu) { continue; }
    $db->setAttribute(1000, true);
    $s = $db->query($sql);
    $n = 0;
    while (($r = $s->fetch(PDO::FETCH_NUM)) !== false) { $n++; }
    echo "buffered rows=$n ", memory_get_peak_usage() - $base < 16 * 1024 * 1024 ? 'flat' : 'grew', "\n";
}
