<?php
// multi_query / more_results / next_result: chains of result sets and OK packets, a failing middle statement, CALL with
// OUT parameters, store vs use per step, commands out of sync, closing mid-chain, report modes, procedural twins, and the
// statement-side more_results after a CALL.
function conn(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
function st(mysqli $m): string
{
    return 'ar=' . json_encode($m->affected_rows) . ' id=' . json_encode($m->insert_id) . ' info=' . json_encode($m->errno ? 'err' : $m->info) . ' fc=' . $m->field_count . ' wc=' . $m->warning_count . ' errno=' . $m->errno . ' [' . $m->sqlstate . '] ' . $m->error;
}
function fr($r)
{
    return $r ? $r->fetch_row() : $r;
}
function t(string $l, callable $f): void
{
    try { echo $l, ': ', json_encode($f()), "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), '|', $e->getCode(), "\n"; }
}
/** Steps through a whole chain: per step the kind, rows (store or use), counters, more_results and next_result. */
function walk(mysqli $m, bool $use = false): void
{
    $i = 0;
    do {
        $r = $use ? $m->use_result() : $m->store_result();
        if ($r) {
            $rows = [];
            while ($row = $r->fetch_row()) { $rows[] = $row; }
            echo "  step $i set ", json_encode($rows), ' nr=', $r->num_rows, ' ', st($m), ' more=', json_encode($m->more_results()), "\n";
            $r->free();
        } else {
            echo "  step $i none ", st($m), ' more=', json_encode($m->more_results()), "\n";
        }
        $i++;
        $ok = $m->next_result();
        echo "  next=", json_encode($ok), ' ', st($m), "\n";
    } while ($ok && $i < 12);
}
mysqli_report(MYSQLI_REPORT_OFF);
$m = conn();
$m->query('CREATE TEMPORARY TABLE tm (id INT AUTO_INCREMENT PRIMARY KEY, a VARCHAR(10))');
echo "--- three statements, store\n";
t('mq', fn() => $m->multi_query("INSERT INTO tm (a) VALUES ('x'),('y'); SELECT id, a FROM tm; UPDATE tm SET a = 'z'; SELECT 1/0, 2"));
echo st($m), "\n";
walk($m);
t('after', fn() => st($m));
t('query ok', fn() => $m->query('SELECT 1')->fetch_row());
echo "--- three statements, use\n";
t('mq', fn() => $m->multi_query("SELECT 1; SELECT 2, 3; DELETE FROM tm WHERE id = 1; SELECT id FROM tm"));
walk($m, true);
echo "--- failing middle statement\n";
t('mq', fn() => $m->multi_query('SELECT 1; SELEC 2; SELECT 3'));
echo st($m), ' more=', json_encode($m->more_results()), "\n";
$r = $m->store_result();
t('first', fn() => $r->fetch_row());
t('more', fn() => $m->more_results());
t('next', fn() => $m->next_result());
echo st($m), ' more=', json_encode($m->more_results()), "\n";
t('next again', fn() => $m->next_result());
echo st($m), "\n";
t('store', fn() => $m->store_result());
t('query ok', fn() => $m->query('SELECT 2')->fetch_row());
echo "--- failing first statement\n";
t('mq', fn() => $m->multi_query('SELEC 1; SELECT 2'));
echo st($m), ' more=', json_encode($m->more_results()), "\n";
t('next', fn() => $m->next_result());
t('query ok', fn() => $m->query('SELECT 2')->fetch_row());
echo "--- failing last statement\n";
t('mq', fn() => $m->multi_query('SELECT 1; SELECT 2; SELEC 3'));
t('n1', fn() => [$m->store_result()->num_rows, $m->next_result()]);
t('n2', fn() => [$m->store_result()->num_rows, $m->next_result()]);
echo st($m), ' more=', json_encode($m->more_results()), "\n";
t('query ok', fn() => $m->query('SELECT 2')->fetch_row());
echo "--- empty and odd queries\n";
foreach (['', ' ', ';', ' ; ', ';;', 'SELECT 1;', 'SELECT 1;;', '; SELECT 1', "SELECT 1;\n", '-- c', 'SELECT 1; -- c'] as $q) {
    t('mq ' . json_encode($q), function () use ($m, $q) {
        $o = [$m->multi_query($q), $m->errno, $m->error, $m->more_results()];
        $n = 0;
        do { $r = $m->store_result(); if ($r) { $o[] = $r->fetch_row(); } $n++; } while ($n < 5 && $m->more_results() && $m->next_result());
        if ($m->errno) { $o[] = [$m->errno, $m->error]; }
        return $o;
    });
    $m->close();
    $m = conn();
    $m->query('CREATE TEMPORARY TABLE tm (id INT AUTO_INCREMENT PRIMARY KEY, a VARCHAR(10))');
}
echo "--- commands out of sync\n";
t('mq', fn() => $m->multi_query('SELECT 1; SELECT 2'));
t('query', fn() => $m->query('SELECT 3'));
echo st($m), "\n";
t('real_query', fn() => $m->real_query('SELECT 3'));
t('ping', fn() => @$m->ping());
echo st($m), "\n";
t('select_db', fn() => $m->select_db(getenv('MYSQLI_DB')));
echo st($m), "\n";
t('multi_query', fn() => $m->multi_query('SELECT 4'));
echo st($m), "\n";
t('set_charset', fn() => $m->set_charset('utf8mb4'));
t('prepare', function () use ($m) { $s = $m->prepare('SELECT 1'); return [$s, $m->errno, $m->error]; });
t('begin', fn() => $m->begin_transaction());
t('commit', fn() => $m->commit());
t('autocommit', fn() => $m->autocommit(true));
t('stat', fn() => $m->stat());
t('thread_id', fn() => is_int($m->thread_id));
t('escape', fn() => $m->real_escape_string("a'b"));
t('sqlstate etc', fn() => st($m));
t('store', fn() => fr($m->store_result()));
t('query still', fn() => $m->query('SELECT 5'));
t('next', fn() => $m->next_result());
t('query still', fn() => $m->query('SELECT 5'));
t('store', fn() => fr($m->store_result()));
t('query ok', fn() => $m->query('SELECT 5')->fetch_row());
t('use in flight query', function () use ($m) {
    $m->multi_query('SELECT 1; SELECT 2');
    $r = $m->use_result();
    return [$m->query('SELECT 3'), $m->errno, $m->error, $r->fetch_row(), $m->more_results(), $m->next_result(), $m->query('SELECT 3'), fr($m->store_result())];
});
t('drain by next', function () use ($m) {
    $m->next_result();
    return [$m->more_results(), fr($m->query('SELECT 7'))];
});
echo "--- a refused command leaves the counters alone\n";
$m->close();
$m = conn();
$m->query('CREATE TEMPORARY TABLE tm (id INT AUTO_INCREMENT PRIMARY KEY, a VARCHAR(10))');
t('mq', fn() => $m->multi_query('SELECT 1; SELECT 2'));
t('state pending', fn() => st($m));
t('stored', fn() => $m->store_result()->num_rows);
t('refused', fn() => [$m->query('SELECT 3') === false, $m->real_query('SELECT 3'), @$m->ping(), $m->select_db(getenv('MYSQLI_DB')), $m->set_charset('utf8mb4'), $m->multi_query('SELECT 4')]);
t('state', fn() => st($m));
t('next', fn() => $m->next_result());
t('state', fn() => st($m));
t('next on pending set', fn() => $m->next_result());
t('stored2', fn() => $m->store_result()->num_rows);
t('state', fn() => st($m));
t('drained', fn() => [$m->more_results(), $m->query('SELECT 1')->num_rows]);
t('mq pending then next', function () use ($m) {
    $m->multi_query('SELECT 1; SELECT 2; SELECT 3');
    $o = [$m->next_result(), $m->errno, $m->more_results()];
    $m->store_result();
    $o[] = $m->next_result();
    $o[] = $m->next_result();
    $o[] = $m->use_result() ? 'use' : 'nouse';
    $o[] = $m->next_result();
    $o[] = $m->more_results();
    return $o;
});
t('rest', function () use ($m) { $r = $m->use_result(); while ($r->fetch_row()) {} $m->next_result(); $m->store_result(); return [$m->more_results(), fr($m->query('SELECT 1'))]; });
echo "--- free_result mid chain\n";
t('mq', fn() => $m->multi_query('SELECT 1; SELECT 2; SELECT 3'));
$r = $m->store_result();
$r->free();
t('more', fn() => $m->more_results());
t("query", fn() => $m->query("SELECT 9") !== false);
t('next', fn() => $m->next_result());
t('use', fn() => fr($m->use_result()));
t("query", fn() => $m->query("SELECT 9") !== false);
t('next', fn() => $m->next_result());
t('next', fn() => $m->next_result());
t('query ok', fn() => fr($m->query('SELECT 9')));
t('unbuffered free', function () use ($m) {
    $m->multi_query('SELECT 1; SELECT 2');
    $r = $m->use_result();
    $r->free();
    return [$m->more_results(), $m->query('SELECT 3') === false ? $m->errno : 'ok', $m->next_result(), fr($m->store_result())];
});
t('drain', function () use ($m) { for ($i = 0; $i < 8 && $m->more_results(); $i++) { $m->next_result(); } return $m->query('SELECT 1')->fetch_row(); });
echo "--- close mid chain\n";
$c = conn();
$c->multi_query('SELECT 1; SELECT 2');
t('close', fn() => $c->close());
t('more', fn() => $c->more_results());
$c = conn();
$c->multi_query('SELECT 1; SELECT 2');
$r = $c->use_result();
$c->close();
t('result after close', fn() => $r->fetch_row());
$c = conn();
$c->multi_query('SELECT 1; SELECT 2');
unset($c);
$c = conn();
t('new conn fine', fn() => $c->query('SELECT 1')->fetch_row());
$c->multi_query('SELECT 3; SELECT 4');
$c2 = conn();
t('thread gone', fn() => $c2->query('SELECT COUNT(*) FROM information_schema.processlist')->fetch_row() > 0);
$c = null;
echo "--- CALL\n";
$m->query('DROP PROCEDURE IF EXISTS pm');
t('create', fn() => $m->query("CREATE PROCEDURE pm(IN a INT, OUT b INT, INOUT c INT) BEGIN SELECT a, 'x'; SET b = a * 2; SET c = c + 1; SELECT b, c; INSERT INTO tm (a) VALUES ('q'); END"));
t('set', fn() => $m->query('SET @c = 10'));
t('call', fn() => $m->multi_query('CALL pm(4, @b, @c)'));
walk($m);
t('out', fn() => $m->query('SELECT @b, @c')->fetch_row());
t('call use', fn() => $m->multi_query('CALL pm(5, @b, @c); SELECT @b, @c'));
walk($m, true);
t('call query', function () use ($m) {
    $r = $m->query('CALL pm(6, @b, @c)');
    return [get_class($r), $r->fetch_row(), $m->more_results()];
});
t('call query out of sync', fn() => $m->query('SELECT 1'));
t('call drain', function () use ($m) { $n = 0; while ($m->more_results() && $m->next_result()) { $n++; } return [$n, st($m)]; });
$m->query('DROP PROCEDURE pm');
$m->query('DROP PROCEDURE IF EXISTS pe');
$m->query('CREATE PROCEDURE pe() BEGIN SELECT 1; SELECT * FROM no_such_table_xx; SELECT 3; END');
t('call error', fn() => $m->multi_query('CALL pe(); SELECT 8'));
walk($m);
t('after', fn() => $m->query('SELECT 8')->fetch_row());
$m->query('DROP PROCEDURE pe');
echo "--- prepared CALL and the connection chain\n";
$m->query('DROP PROCEDURE IF EXISTS ps');
$m->query('CREATE PROCEDURE ps(IN a INT) BEGIN SELECT a; SELECT a + 1; END');
$s = $m->prepare('CALL ps(?)');
$a = 3;
$s->bind_param('i', $a);
t('stmt exec', fn() => $s->execute());
t('conn more', fn() => $m->more_results());
t('stmt more', fn() => $s->more_results());
t('conn next', fn() => [$m->next_result(), $m->errno, $m->error]);
t('conn query', fn() => [$m->query('SELECT 1'), $m->errno, $m->error]);
t('stmt next', fn() => [$s->next_result(), $s->errno, $s->error]);
t('conn more after', fn() => $m->more_results());
$s->close();
$m->query('DROP PROCEDURE ps');
echo "--- report modes\n";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
t('mq strict', fn() => $m->multi_query('SELECT 1; SELEC 2; SELECT 3'));
t('store', fn() => fr($m->store_result()));
t('next strict', fn() => $m->next_result());
t('more', fn() => $m->more_results());
t('state', fn() => st($m));
t('mq strict first', fn() => $m->multi_query('SELEC 1; SELECT 2'));
t('more', fn() => $m->more_results());
t('state', fn() => st($m));
t('query ok', fn() => $m->query('SELECT 2')->fetch_row());
t('sync strict', function () use ($m) { $m->multi_query('SELECT 1; SELECT 2'); return $m->query('SELECT 3'); });
t('sync strict', function () use ($m) { for ($i = 0; $i < 8 && $m->more_results(); $i++) { $m->next_result(); } return $m->query('SELECT 3')->fetch_row(); });
t('next none strict', fn() => $m->next_result());
mysqli_report(MYSQLI_REPORT_ERROR);
t('mq err mode', fn() => @$m->multi_query('SELECT 1; SELEC 2'));
t('next err mode', fn() => @$m->next_result());
mysqli_report(MYSQLI_REPORT_OFF);
$m->close();
$m = conn();
echo "--- procedural\n";
t('p mq', fn() => mysqli_multi_query($m, 'SELECT 1; SELECT 2; INSERT INTO nope VALUES (1)'));
t('p store', fn() => mysqli_fetch_row(mysqli_store_result($m)));
t('p more', fn() => mysqli_more_results($m));
t('p next', fn() => mysqli_next_result($m));
t('p store', fn() => mysqli_fetch_row(mysqli_store_result($m)));
t('p more', fn() => mysqli_more_results($m));
t('p next', fn() => [mysqli_next_result($m), mysqli_errno($m), mysqli_error($m)]);
t('p more', fn() => mysqli_more_results($m));
t('p next none', fn() => mysqli_next_result($m));
t('p ar', fn() => [mysqli_affected_rows($m), mysqli_field_count($m)]);
echo "--- argument and handle errors\n";
t('mq null', fn() => $m->multi_query("a\0b; SELECT 1"));
$m->close();
t('closed mq', fn() => $m->multi_query('SELECT 1'));
t('closed p', fn() => mysqli_multi_query($m, 'SELECT 1'));
t('closed more p', fn() => mysqli_more_results($m));
t('closed next p', fn() => mysqli_next_result($m));
$n = new mysqli();
t('init mq', fn() => $n->multi_query('SELECT 1'));
t('init more', fn() => $n->more_results());
t('init next', fn() => $n->next_result());
echo "--- multi_query then single query statements\n";
$m = conn();
t('semicolon query()', fn() => $m->query('SELECT 1; SELECT 2'));
echo st($m), "\n";
t('mq then query semicolon', function () use ($m) {
    $m->multi_query('SELECT 1');
    $m->store_result();
    return [$m->query('SELECT 1; SELECT 2'), $m->errno, $m->error];
});
t('wc', function () use ($m) {
    $m->multi_query('SELECT 1/0; SELECT 2');
    $o = [st($m)];
    $m->store_result();
    $m->next_result();
    $o[] = st($m);
    $m->store_result();
    return $o;
});
echo "done\n";
