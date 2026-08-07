<?php
$p = tempnam(sys_get_temp_dir(), 'mcpdo');
unlink($p);

$a = new PDO('sqlite:' . $p);
$a->exec('CREATE TABLE f (a INTEGER, b TEXT)');
$a->exec("INSERT INTO f VALUES (7, 'seven')");
unset($a);

$b = new PDO('sqlite:' . $p);
foreach ($b->query('SELECT a, b FROM f')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['a'], "=", $r['b'], "\n";
}
var_dump($b->query('SELECT COUNT(*) FROM f')->fetchColumn());
unset($b);
unlink($p);

// `sqlite:` with an empty name is a private temporary database
$t = new PDO('sqlite:');
$t->exec('CREATE TABLE tmp (x)');
$t->exec('INSERT INTO tmp VALUES (1)');
var_dump($t->query('SELECT COUNT(*) FROM tmp')->fetchColumn());
