<?php
// Command state: what select_db / ping / set_charset / query / real_query / a failed command leave in affected_rows,
// insert_id, info, field_count, warning_count and errno, after an INSERT with a duplicate warning and after a SELECT with a warning.
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
function st(mysqli $m): string
{
    return 'ar=' . json_encode($m->affected_rows) . ' id=' . json_encode($m->insert_id) . ' info=' . json_encode($m->info) . ' fc=' . $m->field_count . ' wc=' . $m->warning_count . ' errno=' . $m->errno;
}
$m->query('CREATE TEMPORARY TABLE t6 (id INT AUTO_INCREMENT PRIMARY KEY, a VARCHAR(20))');
$cmds = [
    'select_db' => fn($m) => $m->select_db(getenv('MYSQLI_DB')),
    'select_db_bad' => fn($m) => $m->select_db('nonexist_db'),
    'ping' => fn($m) => @$m->ping(),
    'set_charset' => fn($m) => $m->set_charset('utf8mb4'),
    'set_charset_bad' => fn($m) => $m->set_charset('nope'),
    'query_select' => fn($m) => $m->query('SELECT 1 UNION SELECT 2'),
    'query_bad' => fn($m) => $m->query('SELEC'),
    'query_ok' => fn($m) => $m->query('SET @a=1'),
    'real_query_sel' => function ($m) { $r = $m->real_query('SELECT 1'); echo '   (pre-store ', st($m), ")\n"; $m->store_result(); return $r; },
];
$pres = [
    'ins' => fn($m) => $m->query("INSERT IGNORE INTO t6 (id,a) VALUES (1,'a'),(1,'dup'),(NULL,'n')"),
    'selwarn' => fn($m) => $m->query('SELECT 1/0'),
];
foreach ($pres as $pk => $pre) {
    foreach ($cmds as $n => $f) {
        $m->query('TRUNCATE t6');
        $pre($m);
        echo "$pk -> $n pre: ", st($m), "\n";
        $r = $f($m);
        echo '   ', json_encode(is_bool($r) ? $r : 'obj'), ' post: ', st($m), "\n";
    }
}
echo "do: "; $m->query('DO 1/0'); echo st($m), "\n";
