<?php
// send_long_data: a BLOB sent in many chunks (1 MB total, chunk boundaries inside multibyte data), several 'b' params,
// a 'b' without send_long_data (empty string), a NULL 'b' variable, long data on an 's' param, bad parameter numbers,
// before bind_param, reset() discarding sent chunks, data sent again for every execute; byte-exact read back.
function t(string $l, callable $f): void
{
    try { $v = $f(); echo $l, ': ', json_encode($v), "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), "\n"; }
}
function back(mysqli $m): array
{
    $o = [];
    foreach ($m->query('SELECT id, LENGTH(b1), MD5(b1), LENGTH(b2), MD5(b2), s FROM l9 ORDER BY id')->fetch_all() as $row) { $o[] = implode(' ', array_map(fn($v) => $v === null ? 'NULL' : $v, $row)); }
    return $o;
}
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
$m->query('CREATE TEMPORARY TABLE l9 (id INT AUTO_INCREMENT PRIMARY KEY, b1 LONGBLOB, b2 LONGBLOB, s TEXT)');
$s = $m->prepare('INSERT INTO l9 (b1, b2, s) VALUES (?, ?, ?)');
t('before bind', fn() => [$s->send_long_data(0, 'x'), $s->errno, $s->error, $s->sqlstate]);
$b1 = 'value ignored'; $b2 = null; $str = 'plain';
$s->bind_param('bbs', $b1, $b2, $str);
$data = '';
for ($i = 0; $i < 4096; $i++) { $data .= str_repeat(chr($i % 256), 200) . "é€"; }
$sent = 0;
foreach (str_split($data, 8191) as $chunk) { if ($s->send_long_data(0, $chunk)) { $sent++; } }
echo 'chunks sent: ', $sent, ' total ', strlen($data), ' md5 ', md5($data), "\n";
t('b2 chunk', fn() => $s->send_long_data(1, 'second'));
t('execute', fn() => [$s->execute(), $s->errno, $s->error]);
// no chunks this time: a 'b' parameter goes as an empty string, a NULL 'b' variable as NULL
$b2 = 'not null';
t('execute no chunks', fn() => [$s->execute(), $s->errno]);
$b1 = null;
t('chunk to null var', fn() => $s->send_long_data(0, 'lost'));
t('execute null var', fn() => [$s->execute(), $s->errno]);
$b1 = '';
t('chunk then reset', fn() => [$s->send_long_data(0, 'discarded'), $s->reset()]);
t('execute after reset', fn() => [$s->execute(), $s->errno]);
t('long data on s param', fn() => [$s->send_long_data(2, 'to s'), $s->errno, $s->error]);
t('bad param number', fn() => [$s->send_long_data(3, 'x'), $s->errno, $s->error]);
t('negative param', fn() => $s->send_long_data(-1, 'x'));
t('p negative param', fn() => mysqli_stmt_send_long_data($s, -1, 'x'));
t('p chunk', fn() => mysqli_stmt_send_long_data($s, 1, 'p-data'));
t('empty chunk', fn() => $s->send_long_data(0, ''));
t('execute p', fn() => [$s->execute(), $s->errno]);
foreach (back($m) as $row) { echo $row, "\n"; }

echo "== big chunks\n";
$m->query('DELETE FROM l9');
$s2 = $m->prepare('INSERT INTO l9 (b1) VALUES (?)');
$null = null;
$s2->bind_param('b', $null);
$null = 'x';
$big = str_repeat('0123456789abcdef', 65536 * 3);
foreach (str_split($big, 1048576) as $chunk) { $s2->send_long_data(0, $chunk); }
t('execute big', fn() => [$s2->execute(), $s2->errno]);
foreach (back($m) as $row) { echo $row, "\n"; }
echo 'expected ', strlen($big), ' ', md5($big), "\n";
$s3 = $m->prepare('SELECT ?');
$v = 'ignored';
$s3->bind_param('b', $v);
$s3->send_long_data(0, 'from long data');
$s3->execute();
t('select long data', fn() => $s3->get_result()->fetch_row());
$s3->execute();
t('select without', fn() => $s3->get_result()->fetch_row());
