<?php
// Review Focus 3 & 4 for mysqli_result: use after free() / close(), data_seek out of range, fetch_* after exhaustion,
// argument errors (method and procedural spelling), constructing a result by hand, var_dump of a result.
function t(string $l, callable $f): void
{
    try { $v = json_encode($f()); echo $l, ': ', $v, "\n"; } catch (Throwable $e) { echo $l, ' ! ', get_class($e), ': ', $e->getMessage(), "\n"; }
}
mysqli_report(MYSQLI_REPORT_OFF);
function conn(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
$m = conn();
$r = $m->query('SELECT 1 a, 2 b UNION SELECT 3, 4');
echo "--- procedural argument errors\n";
t('ds', fn() => mysqli_data_seek($r, -1));
t('fa', fn() => mysqli_fetch_array($r, 9));
t('fall', fn() => mysqli_fetch_all($r, 9));
t('fs', fn() => mysqli_field_seek($r, 9));
t('fs-', fn() => mysqli_field_seek($r, -1));
t('fc', fn() => mysqli_fetch_column($r, 9));
t('fc-', fn() => mysqli_fetch_column($r, -1));
t('fo', fn() => mysqli_fetch_object($r, 'Nope'));
t('fo2', fn() => mysqli_fetch_object($r, 'stdClass', [1]));
abstract class Ab {}
interface If1 {}
t('abs', fn() => $r->fetch_object('Ab'));
$r->data_seek(0);
t('iface', fn() => $r->fetch_object('If1'));
$r->data_seek(0);
echo "--- var_dump / print_r\n";
t('vd', function () use ($r) { ob_start(); var_dump($r); print_r($r); return preg_replace('/#\d+/', '#N', ob_get_clean()); });
t('vd after row', function () use ($r) { $r->fetch_row(); ob_start(); print_r($r); return ob_get_clean(); });
echo "--- read-only properties\n";
foreach (['current_field', 'field_count', 'lengths', 'num_rows', 'type'] as $p) {
    t("write $p", function () use ($r, $p) { $r->$p = 1; return 'no error'; });
}
echo "--- free\n";
t('free', fn() => $r->free());
foreach (['num_rows', 'field_count', 'current_field', 'lengths', 'type'] as $p) {
    t("freed $p", fn() => $r->$p);
}
t('freed fetch_row', fn() => $r->fetch_row());
t('freed fetch_assoc', fn() => $r->fetch_assoc());
t('freed fetch_array', fn() => $r->fetch_array());
t('freed fetch_all', fn() => $r->fetch_all());
t('freed fetch_column', fn() => $r->fetch_column());
t('freed fetch_object', fn() => $r->fetch_object());
t('freed data_seek', fn() => $r->data_seek(0));
t('freed field_seek', fn() => $r->field_seek(0));
t('freed free', fn() => $r->free());
t('freed free_result', fn() => $r->free_result());
t('freed close', fn() => $r->close());
t('freed procedural', fn() => mysqli_fetch_row($r));
t('freed num_rows p', fn() => mysqli_num_rows($r));
t('freed num_fields p', fn() => mysqli_num_fields($r));
t('freed field_tell p', fn() => mysqli_field_tell($r));
t('freed fetch_lengths p', fn() => mysqli_fetch_lengths($r));
t('freed free p', fn() => mysqli_free_result($r));
t('freed iterate', function () use ($r) { foreach ($r as $x) { } return 'done'; });
t('freed getIterator', fn() => $r->getIterator() instanceof Iterator);
t('close twice', function () use ($m) { $r = $m->query('SELECT 1'); $r->close(); return $r->close(); });
echo "--- connection closed under a buffered result\n";
t('closed conn', function () {
    $c = conn();
    $r = $c->query('SELECT 1 UNION SELECT 2');
    $c->close();
    return [$r->num_rows, $r->fetch_row(), $r->data_seek(1), $r->fetch_row(), $r->fetch_row()];
});
echo "--- new mysqli_result\n";
$n = new mysqli();
t('init handle', fn() => new mysqli_result($n));
t('bad mode', fn() => new mysqli_result($m, 9));
t('store', function () use ($m) { $m->real_query('SELECT 1 x'); $r = new mysqli_result($m); return [$r->num_rows, $r->type, $r->fetch_row()]; });
t('use', function () use ($m) { $m->real_query('SELECT 1 x'); $r = new mysqli_result($m, MYSQLI_USE_RESULT); return [$r->num_rows, $r->type, $r->fetch_row(), $r->num_rows]; });
t('no pending result', function () use ($m) { $r = new mysqli_result($m); try { return $r->num_rows; } catch (Error $e) { return $e->getMessage(); } });
class MyRes extends mysqli_result {}
t('subclass', function () use ($m) { $m->real_query('SELECT 1 x'); $r = new MyRes($m); return [get_class($r), $r->fetch_row()]; });
echo "--- report mode: strict\n";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
t('strict data_seek past end', function () use ($m) { $r = $m->query('SELECT 1'); return $r->data_seek(5); });
t('strict exhaustion', function () use ($m) { $r = $m->query('SELECT 1'); $r->fetch_row(); return [$r->fetch_row(), $r->fetch_assoc()]; });
