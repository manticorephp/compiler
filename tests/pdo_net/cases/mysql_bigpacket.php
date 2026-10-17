<?php
// Packets of exactly 0xFFFFFF bytes and larger are split into frames in both directions.
$db = new PDO(getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([16777215 - 30, 16777215 - 3, 16777215, 16777216] as $n) {
    $s = str_repeat('é', intdiv($n, 2));
    $st = $db->prepare('select length(?), md5(?)');
    $st->execute([$s, $s]);
    $r = $st->fetch(PDO::FETCH_NUM);
    $st = null;
    echo $n, ' ', $r[0], ' ', $r[1] === md5($s) ? 'md5 ok' : 'md5 BAD', "\n";
    $st = $db->query('select repeat(\'x\', ' . $n . ')');
    $r = $st->fetch(PDO::FETCH_NUM);
    $st = null;
    echo strlen($r[0]), ' ', md5($r[0]) === md5(str_repeat('x', $n)) ? 'back ok' : 'back BAD', "\n";
}
echo $db->query('select 1')->fetchColumn(), "\n";
