<?php
// warning_count and get_warnings(): mysqli_warning (message, sqlstate, errno, next()), what the SHOW WARNINGS round trip
// leaves in the connection state, the false answers, and the objects' read-only shape.
error_reporting(0);
mysqli_report(MYSQLI_REPORT_OFF);
function conn(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
function st(mysqli $m): string
{
    return 'ar=' . json_encode($m->affected_rows) . ' id=' . json_encode($m->insert_id) . ' info=' . json_encode($m->info) . ' fc=' . $m->field_count . ' wc=' . $m->warning_count . ' errno=' . $m->errno . ' ' . $m->error . ' [' . $m->sqlstate . ']';
}
function t(string $label, callable $f): void
{
    try {
        $r = $f();
        echo $label, ': ', json_encode($r), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
function dump(mysqli_warning $w): void
{
    $n = 0;
    do {
        $n++;
        echo '  ', json_encode([$w->errno, $w->sqlstate, $w->message]), "\n";
    } while ($w->next());
    echo "  rows: $n\n";
}
$m = conn();
echo "-- none\n";
t('fresh connection', fn() => $m->get_warnings());
$m->query('DO 1');
t('after DO', fn() => $m->get_warnings());
$m->query('SELECT 1');
t('after SELECT 1', fn() => mysqli_get_warnings($m));

echo "-- a SELECT with three warnings\n";
$m->query("SELECT 1/0, CAST('abc' AS SIGNED), CAST('x' AS UNSIGNED)");
echo st($m), "\n";
$w = $m->get_warnings();
echo st($m), "\n";
print_r($w);
echo get_class($w), "\n";
dump($w);
t('next() past the end', fn() => $w->next());
t('still on the last', fn() => [$w->errno, $w->sqlstate, $w->message]);
t('again', fn() => $m->get_warnings());
echo st($m), "\n";

echo "-- INSERT IGNORE warnings\n";
$m->query('CREATE TEMPORARY TABLE tw (a TINYINT, b VARCHAR(3))');
$m->query("INSERT IGNORE INTO tw VALUES (300, 'abcdef'), (-300, 'x')");
echo st($m), "\n";
$w = $m->get_warnings();
echo st($m), "\n";
dump($w);
$m->query('SELECT 7');
t('the object outlives the connection state', fn() => $w->message);
$w2 = mysqli_get_warnings($m);
t('procedural after SELECT 7', fn() => $w2);
$m->query("INSERT IGNORE INTO tw VALUES (300, 'a')");
$w3 = mysqli_get_warnings($m);
t('procedural class', fn() => get_class($w3));
dump($w3);
$m->query('INSERT IGNORE INTO tw VALUES (' . implode('),(', array_fill(0, 40, "999, 'a'")) . ')');
t('warning_count with 40 rows', fn() => $m->warning_count);
$w = $m->get_warnings();
$n = 0;
do { $n++; } while ($w->next());
t('rows walked', fn() => $n);

echo "-- NOTE level and mixed levels\n";
$m->query('DROP TABLE IF EXISTS mc_t8_no_such_table');
echo st($m), "\n";
$w = $m->get_warnings();
echo $w === false ? "false\n" : '';
if ($w) { dump($w); }
$m->query("SET @@sql_mode=''");
$m->query("SELECT CAST('1x' AS SIGNED) + CAST('2y' AS SIGNED)");
$w = $m->get_warnings();
if ($w) { dump($w); }
$m->query('SET SESSION sql_mode=DEFAULT');

echo "-- shape\n";
$rc = new ReflectionClass('mysqli_warning');
foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $p) { echo $p->getName(), ' ', $p->getType(), ' ', $p->isPublic() ? 'public' : 'other', "\n"; }
t('final', fn() => $rc->isFinal());
t('constructor private', fn() => $rc->getConstructor()->isPrivate());
t('methods', fn() => array_values(array_filter(array_map(fn($x) => $x->getName(), $rc->getMethods()), fn($n) => !str_starts_with($n, '__mc') && $n !== '__debugInfo' && $n !== '__clone')));
$m->query('SELECT 1/0');
$w = $m->get_warnings();
foreach (['message', 'sqlstate', 'errno'] as $p) {
    t("write $p", function () use ($w, $p) { $w->$p = 'x'; return 'written'; });
}
t('isset message', fn() => isset($w->message));
t('isset errno', fn() => isset($w->errno));
t('property_exists', fn() => [property_exists($w, 'message'), property_exists($w, 'errno'), property_exists($w, 'nope')]);
t('clone', function () use ($w) { $x = clone $w; return 'cloned'; });
t('instanceof', fn() => [$w instanceof mysqli_warning, $w instanceof Traversable]);

echo "-- a result in flight\n";
$m->query('SELECT 1/0, 2');
$m->real_query('SELECT 1/0, 2');
t('real_query pending', fn() => $m->get_warnings());
echo st($m), "\n";
$r = $m->store_result();
echo st($m), "\n";
t('after store_result', fn() => get_class($m->get_warnings()));
echo st($m), "\n";
$m->real_query('SELECT 1/0, 2');
$r = $m->use_result();
t('use_result open', fn() => $m->get_warnings());
$r->fetch_row();
$r->fetch_row();
t('use_result drained', fn() => get_class($m->get_warnings()));
$r->close();

echo "-- statement counts\n";
$m->query("INSERT IGNORE INTO tw VALUES (1,'a')");
t('wc after insert', fn() => $m->warning_count);
$m->query('SELECT 1/0');
t('wc after select', fn() => $m->warning_count);
$m->query('SELECT 1/0')->fetch_all();
t('wc after fetch_all', fn() => $m->warning_count);
$m->query('SELECT 1');
t('wc after clean select', fn() => $m->warning_count);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$m->query('SELECT 1/0');
t('strict mode, get_warnings', fn() => get_class($m->get_warnings()));
mysqli_report(MYSQLI_REPORT_OFF);

echo "-- closed / not initialised\n";
$c = conn();
$c->close();
$i = new mysqli();
foreach (['closed' => $c, 'init' => $i] as $k => $o) {
    t("$k get_warnings", fn() => $o->get_warnings());
    t("$k warning_count", fn() => $o->warning_count);
}
echo "done\n";
