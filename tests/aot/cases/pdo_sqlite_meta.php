<?php

/*
 * getColumnMeta()'s `table` key, and the cursor orientation.
 *
 * `table` comes from sqlite3_column_table_name(), which exists only in a
 * libsqlite3 built with SQLITE_ENABLE_COLUMN_METADATA — bound #[\Ffi\Weak] and
 * called only when sqlite3_compileoption_used() says the option is in. It is
 * NULL for anything that is not a plain table column, which is exactly when php
 * leaves the key out.
 *
 * The orientation is accepted and ignored, as pdo_sqlite does: a sqlite
 * statement is a forward-only cursor and php hands back the next row whatever
 * orientation is asked for.
 */

$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE t (id INTEGER, name TEXT)');
$db->exec('CREATE TABLE u (id INTEGER, t_id INTEGER)');
$db->exec("INSERT INTO t VALUES (1, 'a')");
$db->exec("INSERT INTO t VALUES (2, 'b')");
$db->exec("INSERT INTO t VALUES (3, 'c')");

echo "-- meta keys and table\n";
foreach (['SELECT id FROM t', 'SELECT id AS x FROM t', 'SELECT 1+1 FROM t',
          'SELECT count(*) FROM t', 'SELECT t.id, u.id FROM t LEFT JOIN u ON u.t_id = t.id'] as $q) {
    $s = $db->query($q);
    for ($i = 0; $i < $s->columnCount(); $i++) {
        $m = $s->getColumnMeta($i);
        echo implode(',', array_keys($m)), ' | table=', var_export($m['table'] ?? null, true), "\n";
    }
}

echo "-- a plain column's full meta\n";
var_export($db->query('SELECT id FROM t')->getColumnMeta(0));
echo "\n";

echo "-- every orientation behaves as ORI_NEXT\n";
foreach ([PDO::FETCH_ORI_NEXT, PDO::FETCH_ORI_PRIOR, PDO::FETCH_ORI_FIRST,
          PDO::FETCH_ORI_LAST, PDO::FETCH_ORI_ABS, PDO::FETCH_ORI_REL] as $ori) {
    var_export($db->query('SELECT name FROM t ORDER BY id')->fetch(PDO::FETCH_NUM, $ori));
    echo "\n";
}

echo "-- the cursor still advances\n";
$s = $db->query('SELECT name FROM t ORDER BY id');
var_export($s->fetch(PDO::FETCH_NUM)); echo "\n";
var_export($s->fetch(PDO::FETCH_NUM)); echo "\n";
var_export($s->fetch(PDO::FETCH_NUM, PDO::FETCH_ORI_PRIOR)); echo "\n";
var_export($s->fetch(PDO::FETCH_NUM)); echo "\n";

echo "-- an offset is ignored too\n";
var_export($db->query('SELECT name FROM t ORDER BY id')->fetch(PDO::FETCH_NUM, PDO::FETCH_ORI_ABS, 2));
echo "\n";
