<?php
// Packets that straddle the 16 MiB split: a query whose text is 2^24-2 .. 2^24+1 bytes and 2*(2^24-1) bytes (the empty
// terminating packet), a 17 MB binary value written with an escaped literal and read back, a 33 MB value, and result rows
// above 16 MB in the buffered and the unbuffered path. Byte-identical to php by length and md5.
error_reporting(0);
ini_set("memory_limit", "-1");
mysqli_report(MYSQLI_REPORT_OFF);
function conn(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
function sum(?string $s): string
{
    return $s === null ? 'NULL' : strlen($s) . ':' . md5($s);
}
function blob(int $n, int $seed): string
{
    // all 256 byte values, a different rotation per seed, no repeats shorter than 4099 bytes
    $unit = '';
    for ($i = 0; $i < 4099; $i++) { $unit .= chr(($i * 7 + $seed + intdiv($i, 256)) & 255); }
    return substr(str_repeat($unit, intdiv($n, 4099) + 1), 0, $n);
}
$m = conn();
$mx = (int) $m->query('SELECT @@max_allowed_packet')->fetch_row()[0];
echo 'max_allowed_packet >= 40 MiB: ', json_encode($mx >= 40 * 1048576), "\n";

echo "-- query text around 2^24\n";
foreach ([16777213, 16777214, 16777215, 16777216, 16777217, 33554429, 33554430, 33554431, 33554432] as $len) {
    $sql = 'SELECT 41 /*';
    $sql .= str_repeat('p', $len - strlen($sql) - 2) . '*/';
    $r = $m->query($sql);
    $row = $r ? $r->fetch_row() : null;
    echo $len, ' (', strlen($sql), '): ', json_encode($row), ' ', $m->errno, ' ', $m->error, "\n";
    unset($sql, $r);
    $v = $m->query('SELECT 42');
    echo '  next: ', json_encode($v->fetch_row()), "\n";
}

echo "-- binary value in an escaped literal\n";
$m->query('DROP TABLE IF EXISTS mc_t8_big');
$m->query('CREATE TABLE mc_t8_big (id INT PRIMARY KEY, v LONGBLOB) ENGINE=InnoDB');
foreach ([[1, 1000000], [2, 17000000], [3, 16777215], [4, 16777216], [5, 33000000]] as [$id, $n]) {
    $b = blob($n, $id);
    $esc = $m->real_escape_string($b);
    $ok = $m->query("INSERT INTO mc_t8_big VALUES ($id, '" . $esc . "')");
    echo "insert $id ($n, escaped " . strlen($esc) . '): ', json_encode($ok), ' ', $m->errno, ' ', $m->error, ' ar=', $m->affected_rows, "\n";
    unset($esc);
    $r = $m->query("SELECT v, LENGTH(v), MD5(v) FROM mc_t8_big WHERE id=$id");
    $row = $r ? $r->fetch_row() : [null, null, null];
    echo '  buffered: ', sum($row[0]), ' same=', json_encode($row[0] === $b), ' server ', $row[1], ' ', $row[2], ' md5 ', md5($b), "\n";
    unset($row, $r);
    $r = $m->query("SELECT v FROM mc_t8_big WHERE id=$id", MYSQLI_USE_RESULT);
    $row = $r->fetch_row();
    $r->close();
    echo '  unbuffered: ', sum($row[0]), ' same=', json_encode($row[0] === $b), "\n";
    unset($row, $r, $b);
    echo '  alive: ', json_encode($m->query('SELECT 1')->fetch_row()), "\n";
}

echo "-- big rows in a multi-row result\n";
$r = $m->query('SELECT id, v FROM mc_t8_big WHERE id IN (1, 2, 3) ORDER BY id');
while ($row = $r->fetch_row()) { echo $row[0], ' ', sum($row[1]), "\n"; }
$r->close();
$r = $m->query('SELECT id, v FROM mc_t8_big WHERE id IN (1, 2, 3, 4) ORDER BY id', MYSQLI_USE_RESULT);
while ($row = $r->fetch_assoc()) { echo $row['id'], ' ', sum($row['v']), "\n"; }
$r->close();
$r = $m->query("SELECT REPEAT('ab', 9000000) a, REPEAT('c', 16777215) b, REPEAT('d', 16777216) c");
$row = $r->fetch_assoc();
echo sum($row['a']), ' ', sum($row['b']), ' ', sum($row['c']), "\n";
unset($row, $r);
$r = $m->query("SELECT REPEAT('e', 16777214) a UNION ALL SELECT REPEAT('f', 16777215) UNION ALL SELECT REPEAT('g', 40000000)");
while ($row = $r->fetch_row()) { echo sum($row[0]), "\n"; }
unset($r);
echo 'lengths: ', json_encode($m->query("SELECT LENGTH(v) FROM mc_t8_big ORDER BY id")->fetch_all(MYSQLI_NUM)), "\n";
$m->query('DROP TABLE mc_t8_big');
echo "done\n";
