<?php
// expects-connect-failure: TLS options against mc-my (auto-generated certificate, fails name verification) and mc-my-ssl (:33064, our CA, server cert names 127.0.0.1)
// Numeric option ids: 1006 SSL_KEY, 1007 SSL_CERT, 1008 SSL_CA, 1009 SSL_CAPATH, 1010 SSL_CIPHER, 1013 SSL_VERIFY_SERVER_CERT (the PDO::MYSQL_ATTR_* names are deprecated in 8.5)
$dir = getenv('PDO_SSL_DIR');
$myCa = getenv('PDO_MY_SSL_CA');
$own = "$dir/ca.pem";
$try = function (string $label, int $port, array $o, string $u = 'u') use ($dir) {
    try {
        $db = new PDO("mysql:host=127.0.0.1;port=$port;dbname=d", $u, 'pw', [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + $o);
        $c = $db->query("show session status like 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);
        $v = $db->query("show session status like 'Ssl_version'")->fetch(PDO::FETCH_NUM);
        $n = $db->query('select repeat("x", 200000), 7')->fetch(PDO::FETCH_NUM);
        echo $label, ': ok tls=', json_encode($c[1] !== ''), ' ', $v[1] === '' ? '-' : substr($v[1], 0, 3), ' ', strlen($n[0]), $n[1], "\n";
    } catch (PDOException $e) {
        echo $label, ': ', json_encode(preg_replace("/'@'[0-9.]+'/", "'@'<ip>'", $e->getMessage())), "\n";
    }
};
// mc-my: its own self-signed certificate (no SAN), so only a non-verifying client gets in
$try('plain', 33060, []);
$try('verify off only', 33060, [1013 => false]);
$try('verify on only', 33060, [1013 => true]);
$try('ca, default verify (name mismatch)', 33060, [1008 => $myCa]);
$try('ca, verify on', 33060, [1008 => $myCa, 1013 => true]);
$try('ca, verify off', 33060, [1008 => $myCa, 1013 => false]);
$try('ca missing, verify off', 33060, [1008 => '/nonexistent', 1013 => false]);
$try('ca missing, default verify', 33060, [1008 => '/nonexistent']);
$try('wrong ca, verify off', 33060, [1008 => $own, 1013 => false]);
$try('wrong ca, default verify', 33060, [1008 => $own]);
$try('ca not pem, verify off', 33060, [1008 => '/etc/hosts', 1013 => false]);
$try('capath, verify off', 33060, [1009 => '/nonexistent', 1013 => false]);
$try('cipher, verify off', 33060, [1010 => 'ECDHE-RSA-AES256-GCM-SHA384', 1013 => false]);
$try('bad cipher', 33060, [1010 => 'NOSUCHCIPHER', 1013 => false]);
$try('empty cipher', 33060, [1010 => '', 1013 => false]);
$try('key alone is ignored', 33060, [1006 => '/nonexistent', 1013 => false]);
$try('cert missing', 33060, [1007 => '/nonexistent', 1013 => false]);
$try('cert is not a cert', 33060, [1006 => $myCa, 1007 => $myCa, 1013 => false]);
// mc-my-ssl: a certificate for 127.0.0.1 signed by our CA
$try('ssl: plain', 33064, []);
$try('ssl: ca, default verify', 33064, [1008 => $own]);
$try('ssl: ca, verify on', 33064, [1008 => $own, 1013 => true]);
$try('ssl: ca, verify off', 33064, [1008 => $own, 1013 => false]);
$try('ssl: wrong ca', 33064, [1008 => "$dir/ca2.pem"]);
$try('ssl: wrong ca, verify off', 33064, [1008 => "$dir/ca2.pem", 1013 => false]);
$try('ssl: no ca, default verify', 33064, [1010 => 'ECDHE-RSA-AES256-GCM-SHA384']);
$try('ssl: capath without the ca', 33064, [1009 => $dir . '/nope']);
$try('ssl: cipher', 33064, [1008 => $own, 1010 => 'ECDHE-RSA-AES256-GCM-SHA384']);
$try('ssl: client cert user, no cert', 33064, [1008 => $own], 'cu');
$try('ssl: client cert user, plain', 33064, [], 'cu');
$try('ssl: client cert', 33064, [1008 => $own, 1006 => "$dir/client.key", 1007 => "$dir/client.crt"], 'cu');
$try('ssl: client cert, verify off', 33064, [1013 => false, 1006 => "$dir/client.key", 1007 => "$dir/client.crt"], 'cu');
$try('ssl: client cert without key', 33064, [1008 => $own, 1007 => "$dir/client.crt"], 'cu');
$try('ssl: wrong key', 33064, [1008 => $own, 1006 => $myCa, 1007 => "$dir/client.crt"], 'cu');
$try('ssl: bad password', 33064, [1008 => $own], 'nouser');
