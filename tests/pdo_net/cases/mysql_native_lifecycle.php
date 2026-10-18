<?php
// statement lifecycle over COM_STMT_*: nothing leaks server-side (Prepared_stmt_count), ids are reused, close is exactly once
function dsn(): string { return (string) getenv('PDO_MY_DSN'); }
function pdo(): PDO { return new PDO(dsn(), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]); }
$mon = pdo();
function count_(PDO $mon): int { $s = $mon->query("show global status like 'Prepared_stmt_count'"); $r = $s->fetch(PDO::FETCH_NUM); return (int) $r[1]; }
$base = count_($mon);
function delta(PDO $mon, int $base, string $label): void { echo $label, ': ', count_($mon) - $base, "\n"; }
delta($mon, $base, 'start');

$db = pdo();
$a = $db->prepare('select ?');
delta($mon, $base, 'one prepared');
$a->execute([1]);
echo $a->fetchColumn(), "\n";
$a = null;
delta($mon, $base, 'released');

$keep = [];
for ($i = 0; $i < 60; $i++) { $keep[] = $db->prepare('select ' . $i . ' + ?'); }
delta($mon, $base, '60 kept');
$sum = 0;
foreach ($keep as $i => $s) { $s->execute([1]); $sum += (int) $s->fetchColumn(); }
echo "sum $sum\n";
$keep = [];
$s = null;
delta($mon, $base, 'all released');

// one statement, many executions: the id is reused
$r = $db->prepare('select ? * 2');
$tot = 0;
for ($i = 0; $i < 1500; $i++) { $r->execute([$i]); $tot += (int) $r->fetchColumn(); }
echo "tot $tot\n";
delta($mon, $base, 'reused 1500 times');
$r = null;

// prepare + execute + release, many times: no accumulation, no id exhaustion
for ($i = 0; $i < 400; $i++) { $t = $db->prepare('select ' . $i); $t->execute(); $t->fetchAll(); $t = null; }
delta($mon, $base, '400 short-lived');

// failed prepares leave nothing behind
for ($i = 0; $i < 20; $i++) { try { $db->prepare('selec ' . $i); } catch (PDOException $e) { } }
delta($mon, $base, 'failed prepares');

// a failed execute keeps the statement, closing it still releases it
$db->exec('create temporary table u (a int primary key)');
$ins = $db->prepare('insert into u values (?)');
$ins->execute([1]);
try { $ins->execute([1]); } catch (PDOException $e) { echo "dup\n"; }
$ins->execute([2]);
$cnt = $db->query('select count(*) from u'); echo $cnt->fetchColumn(), "\n"; $cnt = null;
delta($mon, $base, 'with a live insert');
$ins = null;
delta($mon, $base, 'insert released');

// a result left half fetched, a closeCursor, a statement destroyed with rows pending
$h = $db->prepare('select a from u');
$h->execute();
$h->fetch();
$h = null;
$h = $db->prepare('select a from u');
$h->execute();
$h->closeCursor();
$h->execute();
echo count($h->fetchAll()), "\n";
$h = null;
delta($mon, $base, 'half fetched');

// a multi-result CALL is drained and the statement still closes
$db->exec('drop procedure if exists mc_nl');
// a failed run must leave nothing behind
register_shutdown_function(function () { $c = new PDO((string) getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw'); $c->exec('drop procedure if exists mc_nl'); });
$db->exec('create procedure mc_nl(in x int) begin select x; select x + 1; end');
$c = $db->prepare('call mc_nl(?)');
$c->execute([1]);
$c->execute([2]);
$n = 0;
do { $n++; $c->fetchAll(); } while ($c->nextRowset());
echo "rowsets $n\n";
$c = null;
$q7 = $db->query('select 7'); echo $q7->fetchColumn(), "\n"; $q7 = null;
$db->exec('drop procedure mc_nl');
delta($mon, $base, 'after call');

// two statements interleaved on one connection
$p = $db->prepare('select ?');
$q = $db->prepare('select ? + 1');
$p->execute([10]);
echo $p->fetchColumn(), "\n";
$q->execute([20]);
echo $q->fetchColumn(), "\n";
$p->execute([30]);
$q->execute([40]);
echo $p->fetchColumn(), ' ', $q->fetchColumn(), "\n";
delta($mon, $base, 'two live');
$p = null;
delta($mon, $base, 'one live');
$q = null;
delta($mon, $base, 'none live');

// statements die with the connection object
$dd = pdo();
$x1 = $dd->prepare('select 1');
$x2 = $dd->prepare('select 2');
delta($mon, $base, 'two on another connection');
$x1 = null;
$dd = null;
delta($mon, $base, 'one orphan after the connection object dropped (the other statement holds it)');
$x2 = null;
delta($mon, $base, 'all gone');

// a killed connection: closing statements on it is a no-op, the server already dropped them
$dk = pdo();
$qi = $dk->query('select connection_id()'); $id = $qi->fetchColumn(); $qi = null;
$k1 = $dk->prepare('select 1');
$k2 = $dk->prepare('select 2');
$mon->exec('kill ' . $id);
$until = microtime(true) + 5.0;
while (count_($mon) > $base && microtime(true) < $until) { usleep(20000); }
$k1 = null;
$k2 = null;
$dk = null;
echo "killed connection closed quietly\n";
delta($mon, $base, 'after the kill');

// switching between emulated and native at run time
$sw = pdo();
$sw->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$e1 = $sw->prepare('select ?');
delta($mon, $base, 'emulated prepare costs nothing');
$sw->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$n1 = $sw->prepare('select ?');
delta($mon, $base, 'native prepare costs one');
$e1->execute(['e']);
$n1->execute(['n']);
echo $e1->fetchColumn(), $n1->fetchColumn(), "\n";
$n1 = null;
$e1 = null;
$sw = null;
delta($mon, $base, 'end');
