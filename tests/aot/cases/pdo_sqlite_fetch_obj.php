<?php

/*
 * The OBJECT fetch modes. print_r rather than var_dump throughout: php numbers
 * object handles `#N` from its own allocation history, which no other runtime
 * can reproduce.
 */

class Row
{
    public $id;
    public $name;
    public function __construct(public string $tag = '-') {}
    public function label(): string { return $this->tag . ':' . $this->id . ':' . $this->name; }
}

$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE t (id INTEGER, name TEXT)');
$db->exec("INSERT INTO t VALUES (1,'a')");
$db->exec("INSERT INTO t VALUES (2,'b')");
$sql = 'SELECT id, name FROM t';

echo "-- FETCH_OBJ\n";
$s = $db->query($sql);
$o = $s->fetch(PDO::FETCH_OBJ);
echo get_class($o), ' ', $o->id, ' ', $o->name, "\n";
print_r(get_object_vars($o));

echo "-- fetchObject()\n";
$s = $db->query($sql);
$o = $s->fetchObject();
echo get_class($o), ' ', $o->id, "\n";

echo "-- fetchObject('Row')\n";
$s = $db->query($sql);
$o = $s->fetchObject('Row');
echo get_class($o), ' ', $o->label(), "\n";

echo "-- fetchObject('Row', ctor args)\n";
$s = $db->query($sql);
$o = $s->fetchObject('Row', ['T']);
echo $o->label(), "\n";

echo "-- FETCH_CLASS\n";
$s = $db->query($sql);
$s->setFetchMode(PDO::FETCH_CLASS, 'Row');
$o = $s->fetch();
echo get_class($o), ' ', $o->label(), "\n";

echo "-- fetchAll(FETCH_CLASS)\n";
$s = $db->query($sql);
foreach ($s->fetchAll(PDO::FETCH_CLASS, 'Row', ['C']) as $r) { echo $r->label(), "\n"; }

echo "-- FETCH_INTO\n";
$target = new Row('I');
$s = $db->query($sql);
$s->setFetchMode(PDO::FETCH_INTO, $target);
$o = $s->fetch();
var_dump($o === $target);
echo $target->label(), "\n";

echo "-- FETCH_LAZY\n";
$s = $db->query($sql);
$o = $s->fetch(PDO::FETCH_LAZY);
echo get_class($o), ' ', $o->id, ' ', $o->name, "\n";

echo "-- FETCH_OBJ through fetchAll\n";
foreach ($db->query($sql)->fetchAll(PDO::FETCH_OBJ) as $r) { echo $r->id, '=', $r->name, "\n"; }
