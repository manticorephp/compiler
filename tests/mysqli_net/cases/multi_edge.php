<?php
// multi_query edge cases: info under errno != 0, change_user after multi_query, the multi-statements flag after a failed
// multi_query, and results fetched after a close() in the middle of a chain.
function conn(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
function t(string $l, callable $f): void
{
    try { echo $l, ': ', json_encode($f()), "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), "\n"; }
}
mysqli_report(MYSQLI_REPORT_OFF);
$m = conn();
$m->query('CREATE TEMPORARY TABLE te (id INT AUTO_INCREMENT PRIMARY KEY, a VARCHAR(10))');
echo "--- info under errno != 0\n";
// info is the OK packet's message: an UPDATE sets it, a later failing statement does not clear it, and a failing multi_query step keeps what the last OK left
$m->query("INSERT INTO te (a) VALUES ('x'),('y')");
t('info ok', fn() => [$m->errno, $m->info]);
t('info after failed query', fn() => [$m->query('SELEC'), $m->errno, $m->info]);
t('mq fail step', fn() => $m->multi_query("INSERT INTO te (a) VALUES ('z'),('w'); SELEC 2"));
t('step0', fn() => [$m->errno, $m->info]);
t('next', fn() => $m->next_result());
t('step1', fn() => [$m->errno, $m->info]);
t('after', fn() => [$m->query('SELECT 1') !== false, $m->errno, $m->info]);
echo "--- multi_query then change_user then query(\"a;b\")\n";
$m = conn();
t('mq', fn() => $m->multi_query('SELECT 1; SELECT 2'));
t('drain', function () use ($m) { $n = 0; do { $r = $m->store_result(); if ($r) { $r->free(); } $n++; } while ($m->more_results() && $m->next_result()); return $n; });
t('change_user', fn() => $m->change_user(getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB')));
t('query a;b', fn() => [$m->query('SELECT 1; SELECT 2'), $m->errno]);
t('query ok', fn() => $m->query('SELECT 3')->fetch_row());
t('mq again', fn() => $m->multi_query('SELECT 4; SELECT 5'));
t('drain', function () use ($m) { $n = 0; do { $r = $m->store_result(); if ($r) { $r->free(); } $n++; } while ($m->more_results() && $m->next_result()); return $n; });
t('query a;b', fn() => [$m->query('SELECT 1; SELECT 2'), $m->errno]);
echo "--- the flag after a failed multi_query\n";
$m = conn();
t('mq syntax', fn() => $m->multi_query('SELEC 1; SELECT 2'));
t('query a;b', fn() => [$m->query('SELECT 1; SELECT 2'), $m->errno]);
$m = conn();
t('mq ok', fn() => $m->multi_query('SELECT 1; SELECT 2'));
t('mq busy', fn() => [$m->multi_query('SELECT 3'), $m->errno]);
t('drain', function () use ($m) { for ($i = 0; $i < 5 && $m->more_results(); $i++) { $r = $m->store_result(); if ($r) { $r->free(); } $m->next_result(); } return $m->errno; });
t('query a;b', fn() => [$m->query('SELECT 1; SELECT 2'), $m->errno]);
$m = conn();
t('mq args', fn() => $m->multi_query("a\0b; SELECT 1"));
t('query a;b', fn() => [$m->query('SELECT 1; SELECT 2'), $m->errno]);
echo "--- after a close() in the middle of a chain\n";
$mk = function (bool $use) {
    $c = conn();
    $c->query('CREATE TEMPORARY TABLE tl (id INT)');
    $c->query('INSERT INTO tl VALUES (1),(2),(3)');
    $c->multi_query('SELECT id FROM tl; SELECT 2');
    $r = $use ? $c->use_result() : $c->store_result();
    return [$c, $r];
};
[$c, $r] = $mk(false);
$c->close();
t('store fetch_all', fn() => $r->fetch_all());
t('store data_seek', fn() => $r->data_seek(1));
t('store iterator', function () use ($r) { $o = []; foreach ($r as $row) { $o[] = $row; } return $o; });
t('store fetch_row', fn() => $r->fetch_row());
t('store free', fn() => $r->free());
[$c, $r] = $mk(true);
$c->close();
t('use fetch_all', fn() => $r->fetch_all());
t('use data_seek', fn() => $r->data_seek(1));
t('use iterator', function () use ($r) { $o = []; foreach ($r as $row) { $o[] = $row; } return $o; });
t('use fetch_row', fn() => $r->fetch_row());
t('use fetch_array', fn() => $r->fetch_array());
t('use fetch_assoc', fn() => $r->fetch_assoc());
t('use fetch_object', fn() => $r->fetch_object());
t('use num_rows', fn() => $r->num_rows);
t('use free', fn() => $r->free());
echo "done\n";
