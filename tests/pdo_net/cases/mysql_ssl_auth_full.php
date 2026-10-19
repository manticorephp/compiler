<?php
// servers: mysql
// caching_sha2_password FULL authentication over TLS: the password goes in the clear INSIDE the TLS session (no RSA key exchange).
// mysql_ssl_auth_full.pre empties the auth cache of mc-my before each run, so the first login of u/pw is a full authentication.
$dsn = getenv('PDO_MY_DSN');
$ca = getenv('PDO_MY_SSL_CA');
foreach ([[1013 => false], [1013 => false, 1008 => $ca], [1010 => 'ECDHE-RSA-AES256-GCM-SHA384', 1013 => false]] as $i => $o) {
    $db = new PDO($dsn, 'u', 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + $o);
    $q = $db->query("show session status like 'Ssl_cipher'");
    echo $i, ' ', json_encode($q->fetch(PDO::FETCH_NUM)[1] !== ''), "\n";
    $q = null;
    $db = null;
    // each login after the first is a fast authentication; flush again so every iteration is a full one
    exec('docker exec mc-my mysql -h 127.0.0.1 -uroot -ppw -e "flush privileges" 2>/dev/null');
}
foreach ([['u', 'bad'], ['nouser', 'pw']] as [$user, $pass]) {
    try { new PDO($dsn, $user, $pass, [1013 => false, 1008 => $ca]); echo "ok\n"; }
    catch (PDOException $e) { echo json_encode(preg_replace("/'@'[0-9.]+'/", "'@'<ip>'", $e->getMessage())), "\n"; }
}
