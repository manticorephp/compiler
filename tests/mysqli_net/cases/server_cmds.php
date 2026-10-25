<?php
// Server commands: change_user to a second account (created through root, dropped afterwards), kill (another connection, self,
// bad ids), stat (shape only), refresh, and the connection state each of them leaves. A server that refuses a command
// (MySQL 8.4 dropped COM_REFRESH and COM_PROCESS_KILL) answers the same error through mysqli as through php.
error_reporting(0);
mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('MYSQLI_HOST');
$port = (int) getenv('MYSQLI_PORT');
$db = getenv('MYSQLI_DB');
function conn(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
function st(mysqli $m, bool $info = true): string
{
    return 'ar=' . json_encode($m->affected_rows) . ' id=' . json_encode($m->insert_id) . ($info ? ' info=' . json_encode($m->info) : '') . ' fc=' . $m->field_count . ' wc=' . $m->warning_count . ' errno=' . $m->errno . ' ' . $m->error . ' [' . $m->sqlstate . ']';
}
function who(mysqli $m): string
{
    $r = $m->query('SELECT SUBSTRING_INDEX(USER(),"@",1), DATABASE(), @@autocommit, @v, @@character_set_client');
    return $r ? json_encode($r->fetch_row()) : 'query failed: ' . $m->errno . ' ' . $m->error;
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
$root = new mysqli($host, 'root', getenv('MYSQLI_ROOT_PASS') ?: 'pw', $db, $port);
if ($root->connect_errno) { echo "no root: ", $root->connect_error, "\n"; exit(0); }
$root->query("DROP USER IF EXISTS 'mcu8'@'%'");
$root->query("CREATE USER 'mcu8'@'%' IDENTIFIED BY 'pw2'");
$root->query("GRANT ALL ON `$db`.* TO 'mcu8'@'%'");
try {
    echo "-- change_user\n";
    $m = conn();
    $tid = $m->thread_id;
    $m->query('SET @v=5');
    $m->query('SET autocommit=0');
    $m->query('CREATE TEMPORARY TABLE tt (a INT)');
    $m->query('SELECT 1/0');
    $m->set_charset('latin1');
    echo who($m), "\n", st($m), "\n";
    t('change_user', fn() => $m->change_user('mcu8', 'pw2', $db));
    echo st($m), "\n", who($m), ' same thread: ', json_encode($m->thread_id === $tid), ' charset: ', $m->character_set_name(), "\n";
    t('temporary table gone', fn() => $m->query('SELECT * FROM tt'));
    echo st($m), "\n";
    t('change_user no database', fn() => $m->change_user('mcu8', 'pw2', null));
    echo st($m), "\n", who($m), "\n";
    t('change_user empty database', fn() => $m->change_user('mcu8', 'pw2', ''));
    echo st($m), "\n", who($m), "\n";
    t('mysqli_change_user back to u', fn() => mysqli_change_user($m, getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), $db));
    echo who($m), "\n";
    $m->set_charset('gbk');
    t('change_user keeps gbk', fn() => $m->change_user('mcu8', 'pw2', $db));
    echo who($m), ' ', $m->character_set_name(), " ", bin2hex($m->real_escape_string("a\x8c'b")), "\n";
    $m->close();

    $m = conn();
    t('change_user wrong password', fn() => $m->change_user('mcu8', 'wrong', $db));
    echo st($m, false), "\n";
    $m = conn();
    t('change_user unknown user', fn() => $m->change_user('nouser_mc8', 'x', $db));
    echo st($m, false), "\n";
    $m = conn();
    t('change_user denied database', fn() => $m->change_user('mcu8', 'pw2', 'mysql'));
    echo st($m, false), "\n";
    $m = conn();
    t('change_user NUL in user name', fn() => $m->change_user("mcu8\0junk", 'pw2', $db));
    echo st($m), "\n", who($m), "\n";
    t('change_user NUL in database name', fn() => $m->change_user('mcu8', 'pw2', "$db\0junk"));
    echo who($m), "\n";
    $m = conn();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    t('change_user wrong password (strict)', fn() => $m->change_user('mcu8', 'wrong', $db));
    mysqli_report(MYSQLI_REPORT_OFF);
    $c = conn();
    $c->close();
    t('closed change_user', fn() => $c->change_user('a', 'b', 'c'));
    t('init change_user', fn() => (new mysqli())->change_user('a', 'b', 'c'));

    echo "-- kill\n";
    foreach ([0, -1, PHP_INT_MIN] as $id) {
        t("kill($id)", fn() => conn()->kill($id));
        t("mysqli_kill($id)", fn() => mysqli_kill(conn(), $id));
    }
    $c = conn();
    $c->close();
    t('closed kill(0)', fn() => $c->kill(0));
    t('closed kill(1)', fn() => $c->kill(1));
    t('init kill(0)', fn() => (new mysqli())->kill(0));
    t('init kill(1)', fn() => (new mysqli())->kill(1));
    $m = conn();
    $m->query('SELECT 1/0, 2');
    t('kill unknown thread', fn() => $m->kill(99999999));
    echo st($m), "\n";
    $victim = conn();
    $vid = $victim->thread_id;
    t('kill another connection', fn() => $m->kill($vid));
    echo st($m), "\n";
    usleep(300000);
    t('victim query', fn() => $victim->query('SELECT 1') ? 'answered' : $victim->errno . ' ' . $victim->error);
    echo st($victim, false), "\n";
    t('victim ping', fn() => $victim->ping());
    $n = $root->query("SELECT COUNT(*) FROM information_schema.processlist WHERE id=$vid")->fetch_row()[0];
    echo 'victim in processlist: ', $n, "\n";
    t('kill self', fn() => $m->kill($m->thread_id));
    echo st($m), "\n";
    t('after kill self: query', fn() => $m->query('SELECT 1'));
    echo st($m), "\n";
    t('after kill self: ping', fn() => $m->ping());
    t('after kill self: select_db', fn() => $m->select_db($db));
    t('after kill self: stat', fn() => $m->stat());
    t('after kill self: thread_id still readable', fn() => $m->thread_id > 0);
    t('after kill self: close', fn() => $m->close());
    $m = conn();
    $v2 = conn();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    t('kill (strict)', fn() => $m->kill($v2->thread_id));
    t('kill unknown (strict)', fn() => $m->kill(99999999));
    mysqli_report(MYSQLI_REPORT_OFF);
    $m->real_query('SELECT 1');
    t('kill with a result pending', fn() => $m->kill(99999999));
    echo st($m), "\n";

    echo "-- stat\n";
    $m = conn();
    $s = $m->stat();
    echo preg_replace('/\d+(\.\d+)?/', 'N', (string) $s), "\n";
    echo st($m, false), "\n";
    $m->query('SELECT 1/0, 2');
    t('stat shape', fn() => preg_replace('/\d+(\.\d+)?/', 'N', (string) mysqli_stat($m)));
    echo st($m), "\n";
    $m->real_query('SELECT 1');
    t('stat with a result pending', fn() => $m->stat());
    echo st($m), "\n";
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    t('stat with a result pending (strict)', fn() => $m->stat());
    mysqli_report(MYSQLI_REPORT_OFF);
    $c = conn();
    $c->close();
    t('closed stat', fn() => $c->stat());
    t('init stat', fn() => (new mysqli())->stat());

    echo "-- refresh\n";
    $m = conn();
    $m->query('SELECT 1/0, 2');
    foreach ([0, MYSQLI_REFRESH_TABLES, MYSQLI_REFRESH_LOG, MYSQLI_REFRESH_GRANT, MYSQLI_REFRESH_STATUS, 256, -1] as $f) {
        t("refresh($f)", fn() => $m->refresh($f));
        echo '  ', st($m), "\n";
    }
    $r = new mysqli($host, 'root', getenv('MYSQLI_ROOT_PASS') ?: 'pw', $db, $port);
    t('root refresh(0)', fn() => $r->refresh(0));
    t('root refresh(TABLES)', fn() => $r->refresh(MYSQLI_REFRESH_TABLES));
    t('root refresh(STATUS)', fn() => mysqli_refresh($r, MYSQLI_REFRESH_STATUS));
    echo '  ', st($r), "\n";
    $m->real_query('SELECT 1');
    t('refresh with a result pending', fn() => $m->refresh(0));
    echo st($m), "\n";
    $c = conn();
    $c->close();
    t('closed refresh', fn() => $c->refresh(0));
    $i = new mysqli();
    t('init refresh', fn() => $i->refresh(0));
    echo 'init errno ', $i->errno, ' ', $i->error, "\n";
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    t('refresh (strict)', fn() => conn()->refresh(MYSQLI_REFRESH_LOG));
    mysqli_report(MYSQLI_REPORT_OFF);
} finally {
    $root->query("DROP USER IF EXISTS 'mcu8'@'%'");
}
echo "done\n";
