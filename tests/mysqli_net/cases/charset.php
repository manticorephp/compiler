<?php
// set_charset / get_charset / character_set_name and real_escape_string: the seven special bytes, valid and invalid UTF-8,
// multibyte lead bytes (gbk, sjis, big5), NO_BACKSLASH_ESCAPES, escaped values round-tripped through the server, errors.
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
$m = conn();
echo "-- defaults\n";
t('character_set_name', fn() => $m->character_set_name());
echo json_encode($m->get_charset()), "\n";
t('mysqli_character_set_name', fn() => mysqli_character_set_name($m));
t('mysqli_get_charset', fn() => mysqli_get_charset($m));
t('client charset on the server', fn() => $m->query('SELECT @@character_set_client, @@character_set_connection, @@character_set_results')->fetch_row());

echo "-- set_charset\n";
foreach (['utf8mb4', 'utf8', 'latin1', 'gbk', 'sjis', 'big5', 'ascii', 'binary', 'cp1251', 'euckr', 'UTF8MB4', 'Latin1', 'utf16', 'nope', '', "latin1\0x"] as $cs) {
    t('set_charset(' . json_encode($cs) . ')', fn() => $m->set_charset($cs));
    echo '  ', st($m), "\n";
    t('  name', fn() => $m->character_set_name());
    t('  get_charset', fn() => $m->get_charset());
    t('  server', fn() => $m->query('SELECT @@character_set_client')->fetch_row());
}
t('mysqli_set_charset', fn() => mysqli_set_charset($m, 'utf8mb4'));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
t('set_charset(nope) strict', fn() => $m->set_charset('nope'));
mysqli_report(MYSQLI_REPORT_OFF);

echo "-- escape\n";
$samples = [
    'specials' => "\0\n\r\\'\"\x1a",
    'plain' => 'abc def',
    'quote' => "it's \"q\"",
    'backslash' => 'a\\b\\\\c',
    'utf8' => "h\u{e9}llo \u{4e16}\u{754c} \u{1f600}'",
    'invalid utf8' => "a\xff\xfe'b\xc3",
    'truncated utf8' => "x\xe4\xb8'",
    'gbk lead' => "\xbf'\xbf\\\xb0\xa1\x81",
    'sjis lead' => "\x83\x5c'\x95\x5c\x81",
    'big5 lead' => "\xa4\x5c'\xa4\xa4\xf9\x5c",
    'euckr' => "\xb0\xa1'\xa1",
    'high bytes' => implode('', array_map('chr', range(0x80, 0xff))),
    'empty' => '',
];
foreach (['utf8mb4', 'utf8', 'latin1', 'gbk', 'sjis', 'big5', 'euckr', 'ascii', 'binary', 'cp932', 'gb2312', 'ujis', 'eucjpms', 'gb18030'] as $cs) {
    $m->set_charset($cs);
    foreach ($samples as $k => $s) {
        $e = $m->real_escape_string($s);
        echo "$cs $k: ", bin2hex($e), $e === $m->escape_string($s) && $e === mysqli_real_escape_string($m, $s) && $e === mysqli_escape_string($m, $s) ? '' : ' MISMATCH', "\n";
    }
}

echo "-- escaped values round-trip\n";
$m->set_charset('utf8mb4');
$m->query('DROP TABLE IF EXISTS mc_t8_esc');
$m->query('CREATE TABLE mc_t8_esc (id INT AUTO_INCREMENT PRIMARY KEY, v VARBINARY(400)) ENGINE=InnoDB');
foreach (['utf8mb4', 'latin1', 'gbk', 'sjis', 'big5'] as $cs) {
    $m->set_charset($cs);
    foreach ($samples as $k => $s) {
        $m->query('TRUNCATE mc_t8_esc');
        $ok = $m->query("INSERT INTO mc_t8_esc (v) VALUES ('" . $m->real_escape_string($s) . "')");
        $r = $ok ? $m->query('SELECT v FROM mc_t8_esc')->fetch_row()[0] : null;
        echo "$cs $k: ", $ok ? ($r === $s ? 'same' : 'DIFFERENT ' . bin2hex($r)) : 'insert failed ' . $m->errno . ' ' . $m->error, "\n";
    }
}
echo "-- injection through a gbk lead byte, unescaped by addslashes and escaped by the connection\n";
$m->set_charset('gbk');
$evil = "\xbf' OR 1=1 -- ";
$q = "SELECT COUNT(*) FROM (SELECT 1) x WHERE 'a' = '" . $m->real_escape_string($evil) . "'";
t('escaped', fn() => $m->query($q) ? $m->query($q)->fetch_row()[0] : $m->errno . ' ' . $m->error);

echo "-- NO_BACKSLASH_ESCAPES\n";
$m->set_charset('utf8mb4');
$m->query('TRUNCATE mc_t8_esc');
foreach ($samples as $k => $s) { echo "before $k: ", bin2hex($m->real_escape_string($s)), "\n"; break; }
t('set sql_mode', fn() => $m->query("SET SESSION sql_mode=CONCAT(@@sql_mode,',NO_BACKSLASH_ESCAPES')"));
foreach (['utf8mb4', 'gbk', 'latin1'] as $cs) {
    $m->set_charset($cs);
    foreach ($samples as $k => $s) {
        $e = $m->real_escape_string($s);
        echo "$cs $k: ", bin2hex($e);
        $m->query('TRUNCATE mc_t8_esc');
        $ok = $m->query("INSERT INTO mc_t8_esc (v) VALUES ('" . $e . "')");
        $r = $ok ? $m->query('SELECT v FROM mc_t8_esc')->fetch_row()[0] : null;
        echo $ok ? ($r === $s ? ' same' : ' DIFFERENT ' . bin2hex($r)) : ' insert failed ' . $m->errno, "\n";
    }
}
t('sql_mode seen by a query only: escape follows the server flag of the last reply', function () use ($m) {
    $m->query("SET SESSION sql_mode=''");
    $a = bin2hex($m->real_escape_string("a'b\\c"));
    $m->query("SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'");
    $b = bin2hex($m->real_escape_string("a'b\\c"));
    $m->query("SET SESSION sql_mode=''");
    $c = bin2hex($m->real_escape_string("a'b\\c"));
    return [$a, $b, $c];
});
t('flag is per connection', function () use ($m) {
    $m->query("SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'");
    $o = conn();
    return [bin2hex($m->real_escape_string("a'\\")), bin2hex($o->real_escape_string("a'\\"))];
});
$m->query("SET SESSION sql_mode=''");

echo "-- charset survives\n";
$m->set_charset('latin1');
t('after query', function () use ($m) { $m->query('SELECT 1'); return $m->character_set_name(); });
t('after failed set_charset', function () use ($m) { $m->set_charset('nope'); return [$m->character_set_name(), $m->get_charset()->charset]; });
t('real_connect + options charset', function () {
    $x = mysqli_init();
    $x->options(MYSQLI_SET_CHARSET_NAME, 'latin1');
    $x->real_connect(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
    return [$x->character_set_name(), $x->query('SELECT @@character_set_client')->fetch_row()[0]];
});
echo "-- closed / not initialised\n";
$c = conn();
$c->close();
$i = new mysqli();
foreach (['closed' => $c, 'init' => $i] as $k => $o) {
    t("$k set_charset", fn() => $o->set_charset('utf8'));
    t("$k character_set_name", fn() => $o->character_set_name());
    t("$k get_charset", fn() => $o->get_charset());
    t("$k real_escape_string", fn() => $o->real_escape_string('a'));
    t("$k escape_string", fn() => $o->escape_string('a'));
}
$m->query('DROP TABLE mc_t8_esc');
echo "done\n";
