<?php
// servers: mysql
// mysql_native_password logins (mysql 8.4 has the plugin off by default: up.sh starts mc-my-native with it on).
// The server's default plugin is still caching_sha2_password, so a native user is reached through an auth SWITCH.
$base = getenv('PDO_MY_NATIVE_DSN');
foreach ([['nu', 'npw'], ['ne', ''], ['ne', null], ['nu', 'bad'], ['nu', null], ['root', 'pw']] as [$u, $p]) {
    try {
        $db = new PDO($base, $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $s = $db->query('select current_user(), @@version');
        echo $u, ': ', json_encode($s->fetch(PDO::FETCH_NUM)), "\n";
        $s = null;
    } catch (PDOException $e) {
        echo $u, ': ', $e->getMessage(), ' ', $e->getCode(), ' ', json_encode($e->errorInfo), "\n";
    }
}
