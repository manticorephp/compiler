<?php
// Statement errors under every report mode (OFF returns false, ERROR|STRICT throws mysqli_sql_exception with the SQLSTATE,
// ERROR alone records), error/errno/sqlstate/error_list on the statement and the connection, a server error mid-result,
// get_warnings on a statement, CALL with more_results/next_result, a statement used after its connection was closed,
// the closed / uninitialised statement Error texts for every method and property.
function t(string $l, callable $f): void
{
    try { $v = $f(); echo $l, ': ', json_encode($v), "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), ($e instanceof mysqli_sql_exception ? '|' . $e->getSqlState() : ''), "\n"; }
}
function est(mysqli_stmt $s): string
{
    $o = [];
    foreach (['errno', 'error', 'sqlstate', 'error_list'] as $k) {
        try { $o[] = $k . '=' . json_encode($s->$k); } catch (Throwable $e) { $o[] = $k . '!' . $e->getMessage(); }
    }
    return implode(' ', $o);
}
function conn(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
$m = conn();
$m->query('CREATE TEMPORARY TABLE e9 (id INT PRIMARY KEY, v VARCHAR(3))');
$m->query("INSERT INTO e9 VALUES (1, 'a')");
foreach (['OFF' => MYSQLI_REPORT_OFF, 'ERROR' => MYSQLI_REPORT_ERROR, 'STRICT' => MYSQLI_REPORT_STRICT, 'ERROR|STRICT' => MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT] as $name => $mode) {
    echo "== $name\n";
    mysqli_report($mode);
    t('prepare syntax', fn() => [@$m->prepare('SELEC 1'), $m->errno, $m->sqlstate]);
    t('p prepare syntax', fn() => [@mysqli_prepare($m, 'SELEC 1'), $m->errno]);
    t('ctor syntax', function () use ($m) { $s = @new mysqli_stmt($m, 'SELEC 1'); return est($s); });
    t('stmt prepare syntax', function () use ($m) { $s = $m->stmt_init(); return [@$s->prepare('SELEC 1'), est($s)]; });
    $s = $m->prepare('INSERT INTO e9 VALUES (?, ?)');
    $id = 1; $v = 'b';
    $s->bind_param('is', $id, $v);
    t('dup key', fn() => [@$s->execute(), est($s), $m->errno, $m->error]);
    $id = 2; $v = 'toolong';
    t('too long', fn() => [@$s->execute(), est($s)]);
    $id = 3; $v = 'ok';
    t('ok after error', fn() => [$s->execute(), est($s), $m->errno]);
    $m->query('DELETE FROM e9 WHERE id > 1');
    $u = $m->prepare('SELECT ?, ?');
    t('unbound', fn() => [@$u->execute(), est($u)]);
    t('fetch unexecuted', fn() => [@$u->fetch(), est($u)]);
    t('store unexecuted', fn() => [@$u->store_result(), est($u)]);
    t('get_result unexecuted', fn() => [@$u->get_result(), est($u)]);
    t('next_result unexecuted', fn() => [@$u->next_result(), est($u)]);
    t('long data unbound', fn() => [@$u->send_long_data(0, 'x'), est($u)]);
    t('reset', fn() => [$u->reset(), est($u)]);
    t('attr_set ok', fn() => [$u->attr_set(MYSQLI_STMT_ATTR_CURSOR_TYPE, 1), est($u)]);
    $x = 1;
    t('bind ok clears', fn() => [$u->bind_param('ss', $x, $x), est($u)]);
    $k = $m->prepare('SELECT id, 1 / (id - id) * EXP(1000 * id) FROM (SELECT 1 id UNION ALL SELECT 2) z');
    t('error mid-result', function () use ($k, $m) { $o = [@$k->execute()]; $k->bind_result($a, $b); $o[] = @$k->fetch(); $o[] = $a; $o[] = @$k->fetch(); $o[] = est($k); $o[] = $m->errno; return $o; });
    t('error mid-result stored', function () use ($k, $m) { $o = [@$k->execute(), @$k->store_result(), est($k), $m->errno]; $k->bind_result($a, $b); $o[] = @$k->fetch(); $o[] = $a; $k->free_result(); return $o; });
    t('conn usable', fn() => $m->query('SELECT 1')->fetch_row());
}
mysqli_report(MYSQLI_REPORT_OFF);

echo "== warnings\n";
$w = $m->prepare('INSERT IGNORE INTO e9 VALUES (?, ?)');
$id = 1; $v = 'dup';
$w->bind_param('is', $id, $v);
$w->execute();
t('warning_count', fn() => [$m->warning_count, $w->affected_rows]);
t('stmt get_warnings', function () use ($w) { $x = $w->get_warnings(); return $x === false ? false : [$x->errno, $x->sqlstate, $x->message, $x->next()]; });
t('p get_warnings', fn() => mysqli_stmt_get_warnings($w) instanceof mysqli_warning);
$id = 9; $v = 'longer';
$w->execute();
t('truncation warning', function () use ($w) { $x = $w->get_warnings(); return $x === false ? false : [$x->errno, $x->message]; });
t('no warnings', function () use ($m) { $s = $m->prepare('SELECT 1'); $s->execute(); $s->store_result(); return $s->get_warnings(); });

echo "== CALL\n";
$m->query('DROP PROCEDURE IF EXISTS p9');
$m->query("CREATE PROCEDURE p9(IN n INT) BEGIN SELECT n AS a; SELECT n + 1 AS b, 'x' AS c; END");
$c = $m->prepare('CALL p9(?)');
$n = 5;
$c->bind_param('i', $n);
t('execute', fn() => [$c->execute(), $c->field_count, $c->more_results()]);
$c->bind_result($ra);
t('fetch 1', function () use ($c, &$ra) { return [$c->fetch(), $ra, $c->fetch(), $c->more_results()]; });
t('next 1', fn() => [$c->next_result(), $c->field_count, $c->more_results()]);
t('store 2', fn() => [$c->store_result(), $c->num_rows]);
$c->bind_result($rb, $rc);
t('fetch 2', function () use ($c, &$rb, &$rc) { return [$c->fetch(), $rb, $rc, $c->more_results()]; });
t('next 2', fn() => [$c->next_result(), $c->field_count, $c->affected_rows, $c->more_results()]);
t('next 3', fn() => [$c->next_result(), $c->more_results(), est($c)]);
t('conn free', fn() => $m->query('SELECT 1')->fetch_row());
t('busy until next', function () use ($c, $m) { $c->execute(); $c->store_result(); return [$m->query('SELECT 1'), $m->errno, $c->more_results()]; });
t('next while unread', function () use ($c) { $c->free_result(); $c->execute(); return [$c->next_result(), est($c)]; });
t('get_result chain', function () use ($c) {
    $c->execute();
    $o = [$c->get_result()->fetch_all()];
    while ($c->more_results()) { $o[] = $c->next_result(); $r = $c->get_result(); $o[] = $r === false ? false : $r->fetch_all(); }
    return $o;
});
t('p more/next', function () use ($c) { $c->execute(); $c->store_result(); return [mysqli_stmt_more_results($c), mysqli_stmt_next_result($c), mysqli_stmt_more_results($c)]; });
$c->close();
t('re-execute closed chain', fn() => $m->query('SELECT 2')->fetch_row());

echo "== connection closed\n";
$m2 = conn();
$a = $m2->prepare('SELECT 1');
$b = $m2->prepare('SELECT ?');
$p = 1;
$b->bind_param('i', $p);
$b->execute();
$m2->close();
foreach (['execute' => fn() => $a->execute(), 'fetch' => fn() => $a->fetch(), 'store_result' => fn() => $a->store_result(), 'get_result' => fn() => $a->get_result(),
    'result_metadata' => fn() => $a->result_metadata() !== false, 'reset' => fn() => $a->reset(), 'free_result' => fn() => $a->free_result(), 'send_long_data' => fn() => $a->send_long_data(0, 'x'),
    'prepare' => fn() => $a->prepare('SELECT 2'), 'attr_get' => fn() => $a->attr_get(0), 'get_warnings' => fn() => $a->get_warnings(), 'more_results' => fn() => $a->more_results(),
    'b fetch pending' => fn() => $b->fetch(), 'b store pending' => fn() => $b->store_result(), 'b execute' => fn() => $b->execute()] as $l => $f) {
    t($l, fn() => [$f(), est($a), est($b)]);
}
t('props after conn close', fn() => [$a->field_count, $a->param_count, $a->affected_rows, $a->num_rows, $a->insert_id]);
t('close after conn close', fn() => $a->close());
t('stmt_init on closed conn', fn() => $m2->stmt_init());
t('prepare on closed conn', fn() => $m2->prepare('SELECT 1'));
t('new mysqli_stmt on closed conn', fn() => new mysqli_stmt($m2));

echo "== closed / uninitialised statement\n";
$z = $m->prepare('SELECT 1');
$z->close();
$y = $m->stmt_init();
foreach ([$z, $y] as $q => $st) {
    foreach (['attr_get' => fn() => $st->attr_get(0), 'attr_set' => fn() => $st->attr_set(0, 0), 'bind_param' => fn() => $st->bind_param('i', $q), 'bind_result' => fn() => $st->bind_result($q),
        'close' => fn() => $st->close(), 'data_seek' => fn() => $st->data_seek(0), 'data_seek -1' => fn() => $st->data_seek(-1), 'execute' => fn() => $st->execute(), 'fetch' => fn() => $st->fetch(),
        'free_result' => fn() => $st->free_result(), 'get_result' => fn() => $st->get_result(), 'get_warnings' => fn() => $st->get_warnings(), 'more_results' => fn() => $st->more_results(),
        'next_result' => fn() => $st->next_result(), 'num_rows' => fn() => $st->num_rows(), 'reset' => fn() => $st->reset(), 'result_metadata' => fn() => $st->result_metadata(),
        'send_long_data' => fn() => $st->send_long_data(0, ''), 'send_long_data -1' => fn() => $st->send_long_data(-1, ''), 'store_result' => fn() => $st->store_result(),
        'p errno' => fn() => mysqli_stmt_errno($st), 'p error' => fn() => mysqli_stmt_error($st), 'p error_list' => fn() => mysqli_stmt_error_list($st), 'p sqlstate' => fn() => mysqli_stmt_sqlstate($st),
        'p affected_rows' => fn() => mysqli_stmt_affected_rows($st), 'p insert_id' => fn() => mysqli_stmt_insert_id($st), 'p field_count' => fn() => mysqli_stmt_field_count($st),
        'p param_count' => fn() => mysqli_stmt_param_count($st), 'p prepare' => fn() => mysqli_stmt_prepare($st, 'SELECT 1')] as $l => $f) {
        t(($q === 0 ? 'closed ' : 'init ') . $l, $f);
    }
    foreach (['affected_rows', 'insert_id', 'num_rows', 'param_count', 'field_count', 'errno', 'error', 'error_list', 'sqlstate', 'id'] as $p) {
        t(($q === 0 ? 'closed ->' : 'init ->') . $p, fn() => $p === 'id' ? is_int($st->$p) : $st->$p);
    }
}
class MyStmt extends mysqli_stmt { public function __construct() {} }
$mine = new MyStmt();
t('subclass no parent ctor', fn() => $mine->execute());
t('subclass prop', fn() => $mine->errno);
t('subclass prepare', fn() => $mine->prepare('SELECT 1'));
t('read-only prop', function () use ($m) { $s = $m->prepare('SELECT 1'); $s->errno = 5; return $s->errno; });
t('read-only prop 2', function () use ($m) { $s = $m->prepare('SELECT 1'); $s->id = 5; return 1; });
