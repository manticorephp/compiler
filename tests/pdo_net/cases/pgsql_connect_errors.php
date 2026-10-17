<?php
$base = getenv('PDO_PG_DSN');
$try = function (string $dsn, ?string $u, ?string $p) {
    try { new PDO($dsn, $u, $p); echo "ok\n"; }
    catch (PDOException $e) { echo json_encode($e->getMessage()), ' ', $e->getCode(), ' ', json_encode($e->errorInfo), "\n"; }
};
$try($base, 'u', 'bad');
$try($base, 'nouser', 'pw');
$try($base, 'u', null);
$try($base . ';user=u;password=pw', null, null);
$try($base . ';user=zz;password=zz', 'u', 'pw');
$try(preg_replace('/dbname=[^;]*/', 'dbname=nope', $base), 'u', 'pw');
$try(preg_replace('/;dbname=[^;]*/', '', $base), 'u', 'pw');
$try(preg_replace('/port=\d+/', 'port=54399', $base), 'u', 'pw');
$try($base . ';sslmode=disable', 'u', 'pw');
