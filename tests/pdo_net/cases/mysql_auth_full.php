<?php
// servers: mysql
// caching_sha2_password FULL authentication over a plain socket: mysql_auth_full.pre runs `flush privileges` (empties the
// server's auth cache) before each run, so the first login of u/pw asks for the RSA key. Bad credentials always do.
$dsn = getenv('PDO_MY_DSN');
$u = getenv('PDO_USER') ?: 'u';
$p = getenv('PDO_PASS') ?: 'pw';
$db = new PDO($dsn, $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$s = $db->query('select 7');
echo $s->fetchColumn(), "\n";
$s = null;
$db = null;
foreach ([[$u, 'bad'], ['nouser', $p]] as [$user, $pass]) {
    try { new PDO($dsn, $user, $pass); echo "ok\n"; }
    catch (PDOException $e) { echo json_encode($e->getMessage()), ' ', $e->getCode(), ' ', json_encode($e->errorInfo), "\n"; }
}
