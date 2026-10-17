<?php
set_error_handler(function ($n, $s) { if (!(error_reporting() & $n)) { return false; } echo "WARN: $s\n"; return true; });
function pdo(int $mode = PDO::ERRMODE_EXCEPTION): PDO { return new PDO((string) getenv('PDO_PG_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => $mode]); }
function norm(mixed $v): string { return preg_replace('/PID: \d+/', 'PID: N', preg_replace("/^'\\d+\\.\\d+'$/", 'V', var_export($v, true))); }
$all = [0, 1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 12, 14, 15, 16, 17, 18, 19, 20, 21, 1000, 1001, 9999];
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING, PDO::ERRMODE_EXCEPTION] as $mode) {
    echo "== errmode $mode\n";
    $db = pdo($mode);
    foreach ($all as $a) {
        try { $r = norm($db->getAttribute($a)); echo "get $a: ", $r, "\n"; } catch (PDOException $e) { echo "get $a: ", $e->getMessage(), "\n"; }
    }
    foreach ([2 => 5, 0 => 1, 1 => 1, 4 => 1, 6 => 1, 7 => 1, 9999 => 1, 1000 => true, 20 => true, 8 => 2, 3 => $mode] as $a => $v) {
        try { $r = norm($db->setAttribute($a, $v)); echo "set $a: ", $r, " ", json_encode($db->errorInfo()), "\n"; } catch (PDOException $e) { echo "set $a: ", $e->getMessage(), "\n"; }
    }
    echo 'version matches show: ';
    $s = $db->query('show server_version');
    var_dump($db->getAttribute(PDO::ATTR_SERVER_VERSION) === $s->fetchColumn());
    echo 'client version string: ', is_string($db->getAttribute(PDO::ATTR_CLIENT_VERSION)) ? 'string' : 'other', "\n";
}
echo "== constructor options\n";
$db = new PDO((string) getenv('PDO_PG_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_TIMEOUT => 5, 1000 => true, PDO::ATTR_EMULATE_PREPARES => false]);
var_dump($db->getAttribute(1000), $db->getAttribute(PDO::ATTR_EMULATE_PREPARES));
echo "== server info follows the session\n";
$db->exec("set client_encoding = 'LATIN1'");
$db->exec("set datestyle = 'SQL, DMY'");
echo norm($db->getAttribute(PDO::ATTR_SERVER_INFO)), "\n";
echo norm($db->getAttribute(PDO::ATTR_CONNECTION_STATUS)), "\n";
echo "== quote\n";
$db = pdo();
foreach (["a'b", "a\\b", "a\\'b", '', "x\0y", "é", "''", "\xff\xfe", "ok"] as $x) { echo json_encode($x), ' => ', json_encode($db->quote($x)), "\n"; }
foreach ([PDO::PARAM_LOB, PDO::PARAM_INT, PDO::PARAM_STR, PDO::PARAM_BOOL, PDO::PARAM_NULL, PDO::PARAM_STR | PDO::PARAM_STR_NATL] as $t) { echo "type $t => ", json_encode($db->quote("a\\b'c", $t)), "\n"; }
$db->exec('set standard_conforming_strings = off');
foreach (["a'b", "a\\b\\'", 'plain'] as $x) { echo json_encode($x), ' => ', json_encode($db->quote($x)), "\n"; }
$q = $db->quote("it's\\");
$s = $db->query('select ' . $q);
echo 'roundtrip ', json_encode($s->fetchColumn()), "\n";
$db->exec('set standard_conforming_strings = on');
echo "== DISABLE_PREPARES\n";
foreach ([false, true] as $dis) {
    $db = pdo();
    $db->setAttribute(1000, $dis);
    $db->exec('create temp table t(a int, b text)');
    $s = $db->prepare('insert into t values (?, ?)');
    foreach ([1, 2, 3] as $i) { $s->execute([$i, "r$i"]); echo $s->rowCount(); }
    echo "\n";
    $s = $db->prepare('select a, b from t where a > :min order by a');
    $s->execute([':min' => 1]); echo json_encode($s->fetchAll(PDO::FETCH_ASSOC)), "\n";
    $s->execute([':min' => 2]); echo json_encode($s->fetchAll(PDO::FETCH_NUM)), "\n";
    $s = $db->prepare('select nope from t');
    try { $s->execute(); } catch (PDOException $e) { echo $e->errorInfo[0], "\n"; }
    $s = null;
    $db = null;
}
