<?php
// COPY: copyFromArray/FromFile/ToArray/ToFile against php's pdo_pgsql (php: Pdo\Pgsql via PDO::connect).
error_reporting(E_ALL & ~E_DEPRECATED);
function pdo(array $o = []): PDO { return PDO::connect((string) getenv("PDO_PG_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
function attempt(string $label, callable $f): void
{
    try { $r = $f(); echo $label, ' ', json_encode($r), "\n"; }
    catch (PDOException $e) { echo $label, ' PDOException ', json_encode($e->getMessage()), "\n"; }
}
function rows(PDO $db): string { return json_encode($db->query('select id, s from ct order by id nulls last, s')->fetchAll(PDO::FETCH_NUM)); }
$db = pdo();
$db->exec('create temp table ct(id int, s text)');

// round trip: tab, newline, backslash, NULL, empty string, UTF-8 survive the text format
$ins = $db->prepare('insert into ct values (?, ?)');
$vals = [[1, "a\tb"], [2, "l1\nl2"], [3, 'back\\slash'], [4, null], [5, ''], [6, "h\u{e9}llo \u{2603}"], [7, "cr\rend"], [8, '\\N']];
foreach ($vals as [$i, $s]) { $ins->execute([$i, $s]); }
$out = $db->copyToArray('ct');
echo json_encode($out), "\n";
$before = rows($db);
$db->exec('truncate ct');
var_dump($db->copyFromArray('ct', $out));
echo rows($db) === $before ? "round trip same\n" : "round trip DIFFERENT\n";

// delimiter, NULL marker, column list; only the first byte of a separator counts, '' means tab
$db->exec('truncate ct');
var_dump($db->copyFromArray('ct', ['1|x', '2|NIL', "3|z\n"], '|', 'NIL'));
echo rows($db), "\n";
echo json_encode($db->copyToArray('ct', ',', 'NULL', 's,id')), "\n";
echo json_encode($db->copyToArray('ct', ';;', '-')), "\n";
echo json_encode($db->copyToArray('ct', '', '-', 'id')), "\n";
$db->exec('truncate ct');
var_dump($db->copyFromArray('ct', ['only-s'], "\t", '\\N', 's'));
echo rows($db), "\n";

// row sources: assoc keys, generator, scalars (an empty string is skipped), missing trailing newline
$db->exec('truncate ct');
var_dump($db->copyFromArray('ct', ['a' => "1\tx", 'b' => "2\ty"]));
var_dump($db->copyFromArray('ct', (function () { yield "3\tg"; yield ''; yield "4\th\n"; })()));
var_dump($db->copyFromArray('ct', []));
echo rows($db), "\n";

// files: ToFile truncates and writes, FromFile reads a file without a trailing newline
$f = tempnam(sys_get_temp_dir(), 'mccp');
file_put_contents($f, 'old content');
var_dump($db->copyToFile('ct', $f));
echo json_encode(file_get_contents($f)), "\n";
$db->exec('truncate ct');
file_put_contents($f, "10\tq\n11\t\\N\n12\tlast");
var_dump($db->copyFromFile('ct', $f));
echo rows($db), "\n";
file_put_contents($f, "1,a\n2,b\n");
$db->exec('truncate ct');
var_dump($db->copyFromFile('ct', $f, ',', '\\N', 'id,s'));
echo rows($db), "\n";

// errors are PDOExceptions with the server's text; the connection stays usable and the load is all-or-nothing
$db->exec('truncate ct');
attempt('bad table', fn () => $db->copyFromArray('nosuch', ["1\ta"]));
attempt('bad table to', fn () => $db->copyToArray('nosuch'));
attempt('bad column', fn () => $db->copyFromArray('ct', ["a\tb"], "\t", '\\N', 'nosuchcol'));
attempt('extra column', fn () => $db->copyFromArray('ct', ["1\ta", "2\tb\tc", "3\tx"]));
attempt('bad int', fn () => $db->copyFromArray('ct', ["zz\ta"]));
attempt('bad syntax', fn () => $db->copyFromArray('ct', ["1\ta"], "\t", '\\N', ''));
attempt('later', fn () => $db->query('select count(*) from ct')->fetchColumn());
attempt('open ToFile', fn () => $db->copyToFile('ct', '/nonexistent/dir/x'));
attempt('open FromFile', fn () => $db->copyFromFile('ct', '/nonexistent/dir/x'));
file_put_contents($f, 'keep');
attempt('bad table ToFile', fn () => $db->copyToFile('nosuch', $f));
echo json_encode(file_get_contents($f)), "\n";
file_put_contents($f, "1\tq\nbad\n");
attempt('bad FromFile', fn () => $db->copyFromFile('ct', $f));
attempt('still usable', fn () => $db->query('select 7')->fetchColumn());
unlink($f);

// error modes, and a failed COPY inside a transaction poisons it like any other error
$q = pdo([PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
var_dump($q->copyFromArray('nosuch', ['x']));
echo json_encode($q->errorInfo()), "\n";
var_dump($q->copyToArray('nosuch'));
$db->beginTransaction();
attempt('in txn', fn () => $db->copyFromArray('nosuch', ["1\ta"]));
var_dump($db->inTransaction());
$db->rollBack();
attempt('after rollback', fn () => $db->copyFromArray('ct', ["1\ta"]));

// the other direction injected through the table name is refused with HY000 and an empty message; the connection stays usable (php hangs on the mirror case)
attempt('wrong direction in', fn () => $db->copyFromArray('ct TO STDOUT; --', ["1\ta"]));
attempt('usable', fn () => $db->query('select 8')->fetchColumn());

// big loads: many chunks, and a single row larger than one CopyData message
$db->exec('truncate ct');
$many = [];
for ($i = 0; $i < 6000; $i++) { $many[] = $i . "\t" . str_repeat('x', 90); }
var_dump($db->copyFromArray('ct', $many));
$huge = str_repeat('y', 200000);
var_dump($db->copyFromArray('ct', ["-1\t" . $huge]));
echo json_encode($db->query('select count(*), sum(length(s)), max(length(s)) from ct')->fetch(PDO::FETCH_NUM)), "\n";
$back = $db->copyToArray('ct');
echo count($back), ' ', strlen(implode('', $back)), "\n";
$f = tempnam(sys_get_temp_dir(), 'mccp');
var_dump($db->copyToFile('ct', $f));
echo filesize($f), "\n";
$db->exec('truncate ct');
var_dump($db->copyFromFile('ct', $f));
echo json_encode($db->query('select count(*), sum(length(s)) from ct')->fetch(PDO::FETCH_NUM)), "\n";
unlink($f);

// the pgsql* aliases of php's PDO class, and a connection that is not pgsql
$c = new PDO((string) getenv("PDO_PG_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$c->exec('create temp table cc(a int)');
var_dump($c->pgsqlCopyFromArray('cc', ["1", "2"]));
echo json_encode($c->pgsqlCopyToArray('cc')), "\n";
$l = new PDO('sqlite::memory:');
try { $l->pgsqlCopyToArray('x'); echo "no error\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
