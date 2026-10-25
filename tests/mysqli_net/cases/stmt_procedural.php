<?php
// The mysqli_stmt_* procedural twins (and mysqli_prepare / mysqli_stmt_init / mysqli_execute_query) on the happy path
// and the statement's dump; a prepare / execute / fetch cycle repeated many times with the same bound variables.
mysqli_report(MYSQLI_REPORT_OFF);
$m = mysqli_connect(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
mysqli_query($m, 'CREATE TEMPORARY TABLE pr9 (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(10), n DOUBLE)');
$s = mysqli_stmt_init($m);
var_dump(mysqli_stmt_prepare($s, 'INSERT INTO pr9 (v, n) VALUES (?, ?)'));
$v = 'a'; $n = 1.25;
var_dump(mysqli_stmt_bind_param($s, 'sd', $v, $n));
for ($i = 0; $i < 5; $i++) {
    $v = 'row' . $i; $n = $i / 4;
    mysqli_stmt_execute($s);
    echo 'insert ', $i, ': ar=', mysqli_stmt_affected_rows($s), ' id=', mysqli_stmt_insert_id($s), ' pc=', mysqli_stmt_param_count($s), ' fc=', mysqli_stmt_field_count($s), "\n";
}
var_dump(mysqli_stmt_errno($s), mysqli_stmt_error($s), mysqli_stmt_error_list($s), mysqli_stmt_sqlstate($s));
var_dump(mysqli_stmt_close($s));
$q = mysqli_prepare($m, 'SELECT id, v, n FROM pr9 WHERE id > ? ORDER BY id');
$min = 1;
mysqli_stmt_bind_param($q, 'i', $min);
mysqli_stmt_execute($q);
var_dump(mysqli_stmt_store_result($q), mysqli_stmt_num_rows($q));
mysqli_stmt_bind_result($q, $id, $rv, $rn);
while (mysqli_stmt_fetch($q)) { echo $id, ' ', $rv, ' ', var_export($rn, true), "\n"; }
mysqli_stmt_data_seek($q, 1);
var_dump(mysqli_stmt_fetch($q), $id);
$md = mysqli_stmt_result_metadata($q);
var_dump(mysqli_num_fields($md), mysqli_fetch_field($md)->name);
mysqli_free_result($md);
mysqli_stmt_free_result($q);
var_dump(mysqli_stmt_result_metadata($q));
var_dump(mysqli_stmt_reset($q));
var_dump(mysqli_stmt_attr_set($q, MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH, 1), mysqli_stmt_attr_get($q, MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH));
mysqli_stmt_execute($q);
$r = mysqli_stmt_get_result($q);
var_dump(mysqli_fetch_all($r, MYSQLI_ASSOC));
var_dump(mysqli_stmt_more_results($q), mysqli_stmt_next_result($q), mysqli_stmt_get_warnings($q));
var_dump(mysqli_execute_query($m, 'SELECT COUNT(*) FROM pr9 WHERE n >= ?', [0.5])->fetch_row());
echo preg_replace('/\[id\] => \d+/', '[id] => N', print_r($q, true));
echo "\n";

echo "== repeated cycle\n";
$sum = 0;
$x = 0;
$q2 = mysqli_prepare($m, 'SELECT ? + 1, REPEAT(\'z\', ?)');
mysqli_stmt_bind_param($q2, 'ii', $x, $x);
mysqli_stmt_bind_result($q2, $o1, $o2);
for ($i = 0; $i < 300; $i++) {
    $x = $i % 7;
    mysqli_stmt_execute($q2);
    mysqli_stmt_fetch($q2);
    $sum += $o1 + strlen($o2);
    mysqli_stmt_free_result($q2);
}
echo 'sum ', $sum, "\n";
for ($i = 0; $i < 100; $i++) {
    $t = $m->prepare('SELECT ?');
    $t->bind_param('s', $v);
    $t->execute();
    $t->get_result()->fetch_row();
    $t->close();
}
echo 'prepared/closed 100: ', json_encode($m->query("SHOW SESSION STATUS LIKE 'Com_stmt_close'")->fetch_row()[1] >= 100), "\n";
