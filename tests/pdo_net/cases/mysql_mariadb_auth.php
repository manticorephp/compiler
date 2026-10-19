<?php
// servers: mariadb
// MariaDB-only login plugins: user `ed` (created by up.sh) uses ed25519, which php answers with 2054 naming client_ed25519.
foreach (['ed', 'u'] as $u) foreach (['pw', 'bad'] as $pw) {
    try { $db = new PDO(getenv('PDO_MY_DSN'), $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); echo "$u/$pw ok\n"; }
    catch (PDOException $e) { echo "$u/$pw ", preg_replace("/'@'[0-9.]+'/", "'@'<ip>'", $e->getMessage()), "\n"; }
}
