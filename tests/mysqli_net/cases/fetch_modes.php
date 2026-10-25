<?php
// mysqli_result fetching: fetch_row / assoc / array (NUM, ASSOC, BOTH) / all / column / object (class, constructor args,
// property-before-constructor order), exhaustion, data_seek, foreach, empty and NULL cells, duplicate and numeric column names.
function t(string $l, callable $f): void
{
    try { $v = json_encode($f()); echo $l, ': ', $v, "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), "\n"; }
}
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
$m->query('CREATE TEMPORARY TABLE t6 (id INT AUTO_INCREMENT PRIMARY KEY, a VARCHAR(20), b INT NULL)');
$m->query("INSERT INTO t6 (a,b) VALUES ('x',1),('y',NULL),('z',3)");
$r = $m->query('SELECT id, a, b FROM t6 ORDER BY id');
t('row', fn() => $r->fetch_row());
t('lengths', fn() => $r->lengths);
t('cur', fn() => $r->current_field);
t('assoc', fn() => $r->fetch_assoc());
t('both', fn() => $r->fetch_array());
t('end row', fn() => $r->fetch_row());
t('end assoc', fn() => $r->fetch_assoc());
t('lengths end', fn() => $r->lengths);
t('end array', fn() => $r->fetch_array(MYSQLI_NUM));
t('end obj', fn() => $r->fetch_object());
t('end all', fn() => $r->fetch_all());
t('end column', fn() => $r->fetch_column());
t('seek 1', fn() => $r->data_seek(1));
t('after seek', fn() => $r->fetch_assoc());
t('seek 3', fn() => $r->data_seek(3));
t('seek 4', fn() => $r->data_seek(4));
t('seek -1', fn() => $r->data_seek(-1));
t('seek 0', fn() => $r->data_seek(0));
t('lengths after seek', fn() => $r->lengths);
t('fa num', fn() => $r->fetch_array(MYSQLI_NUM));
t('fa assoc', fn() => $r->fetch_array(MYSQLI_ASSOC));
t('fa both', fn() => $r->fetch_array(MYSQLI_BOTH));
t('fa mode 9', fn() => $r->fetch_array(9));
t('fa mode 0', fn() => $r->fetch_array(0));
t('fa mode 4', fn() => $r->fetch_array(4));
$r->data_seek(0);
t('all assoc', fn() => $r->fetch_all(MYSQLI_ASSOC));
t('all after', fn() => $r->fetch_all());
$r->data_seek(1);
t('all num from 1', fn() => $r->fetch_all(MYSQLI_NUM));
$r->data_seek(0);
t('all both', fn() => $r->fetch_all(MYSQLI_BOTH));
t('all mode', fn() => $r->fetch_all(77));
$r->data_seek(0);
t('col', fn() => $r->fetch_column());
t('col1', fn() => $r->fetch_column(1));
t('col2', fn() => $r->fetch_column(2));
t('col3', fn() => $r->fetch_column(3));
t('col-1', fn() => $r->fetch_column(-1));
t('col end', fn() => $r->fetch_column());
t('col bad at end', fn() => $r->fetch_column(5));
$r->data_seek(0);
t('obj', fn() => $r->fetch_object());
class P { public $id; public $a; public $b; public $ctor; function __construct(...$a) { $this->ctor = $a; } }
class Q { public $id; public $x; public $a; public $b; public $p1; public $p2; function __construct($p1 = null, $p2 = null) { $this->p1 = $p1; $this->p2 = $p2; } }
t('objP', fn() => $r->fetch_object('P'));
t('objP args', fn() => $r->fetch_object('P', [1, 'two']));
$r->data_seek(0);
t('objQ', fn() => $r->fetch_object('Q', ['a', 'b']));
t('obj no class', fn() => $r->fetch_object('Nope'));
t('obj args no ctor', fn() => $r->fetch_object('stdClass', [1]));
abstract class Ab {}
t('obj abstract', fn() => $r->fetch_object('Ab'));
$r->data_seek(0);
$r->fetch_all();
t('obj bad args at end', fn() => $r->fetch_object('stdClass', [1]));
echo "foreach:\n";
$r->data_seek(2);
foreach ($r as $k => $row) { echo $k, ' ', json_encode($row), "\n"; }
echo "foreach again:\n";
foreach ($r as $k => $row) { echo $k, ' ', json_encode($row), "\n"; }
t('iterator', fn() => $r->getIterator() instanceof Iterator);
t('numrows', fn() => $r->num_rows);
t('field_count', fn() => $r->field_count);
t('type', fn() => $r->type);
t('fseek 2', fn() => $r->field_seek(2));
t('cur', fn() => $r->current_field);
t('fseek 9', fn() => $r->field_seek(9));
t('fseek -1', fn() => $r->field_seek(-1));
t('proc', fn() => [mysqli_num_rows($r), mysqli_num_fields($r), mysqli_field_tell($r)]);
t('fetch_lengths no row', fn() => mysqli_fetch_lengths($r));
$r->data_seek(0);
$r->fetch_row();
t('lengths row0', fn() => [$r->lengths, mysqli_fetch_lengths($r)]);
$r->free();
echo "--- empty, NULL, empty string, duplicates, numeric names\n";
$r = $m->query('SELECT 1 a WHERE 1=0');
t('empty', fn() => [$r->num_rows, $r->fetch_row(), $r->fetch_assoc(), $r->fetch_all(), $r->fetch_column(), $r->lengths]);
t('seek0 empty', fn() => $r->data_seek(0));
t('empty iter', function () use ($r) { foreach ($r as $x) { return 'x'; } return 'none'; });
$r = $m->query('SELECT 1 a, 2 a, \'x\' b');
t('dup assoc', fn() => $r->fetch_assoc());
$r->data_seek(0);
t('dup both', fn() => $r->fetch_array());
$r->data_seek(0);
t('dup obj', fn() => $r->fetch_object());
$r = $m->query("SELECT NULL n, '' e, 'abc' s, 'é' u");
t('nulls', fn() => [$r->fetch_row(), $r->lengths]);
$r = $m->query("SELECT 5 a, 'k' `7`, 'z' `1.5`");
t('numkey keys', fn() => array_keys($r->fetch_array()));
$r->data_seek(0);
t('numkey assoc', function () use ($r) { ob_start(); var_dump($r->fetch_assoc()); return ob_get_clean(); });
$r->data_seek(0);
t('numkey obj', fn() => $r->fetch_object());
$r = $m->query('SELECT 5 `` , 6 `x`');
t('empty name', function () use ($r) { ob_start(); var_dump($r->fetch_assoc()); return ob_get_clean(); });
echo "--- fetch_object: property order, magic, typed, readonly\n";
class W2 { public $x = 'default'; public $arg; public function __construct($arg = null) { $this->arg = $arg; echo 'ctor sees x=', var_export($this->x, true), "\n"; } }
$r = $m->query("SELECT 'a' x");
t('W2', fn() => $r->fetch_object('W2', ['k']));
$r = $m->query("SELECT 'a' x");
class Ws { public $z = 'dz'; public function __set($k, $v) { echo "set $k\n"; } }
$r = $m->query("SELECT 'a' x");
t('Ws magic set', fn() => $r->fetch_object('Ws'));
$r = $m->query("SELECT 'a' x");
class Tp { public int $x = 0; }
$r = $m->query("SELECT 'a' x");
$r = $m->query("SELECT '5' x");
t('Tp coerce', fn() => $r->fetch_object('Tp'));
