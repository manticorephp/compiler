<?php
// TLS to mc-pg-ssl (:54323): the session really is encrypted (pg_stat_ssl), and a normal workload runs over it.
$dir = getenv('PDO_SSL_DIR');
$base = 'pgsql:host=127.0.0.1;port=54323;dbname=d';
foreach (['require', "verify-ca;sslrootcert=$dir/ca.pem", "verify-full;sslrootcert=$dir/ca.pem"] as $mode) {
    $db = new PDO("$base;sslmode=$mode", 'u', 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $db->query('select ssl, version is not null as v, cipher is not null as c from pg_stat_ssl where pid = pg_backend_pid()');
    echo strtok($mode, ';'), ' ', json_encode($stmt->fetch(PDO::FETCH_NUM)), "\n";
    $stmt = null;
    $db->exec('create temp table t (id int, v text)');
    $db->beginTransaction();
    $ins = $db->prepare('insert into t values (?, ?)');
    for ($k = 0; $k < 50; $k++) { $ins->execute([$k, str_repeat('x', $k * 7)]); }
    $db->commit();
    echo $db->query('select count(*), sum(length(v)) from t')->fetch(PDO::FETCH_NUM)[1], "\n";
    // a result far bigger than one TLS record (16 KiB) and a bound parameter bigger than that
    $big = $db->query("select repeat('ab', 300000)")->fetchColumn();
    echo strlen($big), ' ', md5($big), "\n";
    $echo = $db->prepare('select length(cast(:p as text)), md5(cast(:p as text))');
    $echo->execute(['p' => str_repeat('z', 200000)]);
    echo json_encode($echo->fetch(PDO::FETCH_NUM)), "\n";
    try { $db->query('select * from nope'); } catch (PDOException $e) { echo $e->errorInfo[0], "\n"; }
    echo $db->query('select 1')->fetchColumn(), "\n";
    $db = null;
}
// prefer (the default) and allow end up encrypted against a TLS-only server; ATTR_TIMEOUT does not disturb it
foreach (['prefer', 'allow', ''] as $mode) {
    $dsn = $mode === '' ? $base : "$base;sslmode=$mode";
    $db = new PDO($dsn, 'u', 'pw', [PDO::ATTR_TIMEOUT => 10]);
    echo $mode === '' ? 'default' : $mode, ' ', var_export($db->query('select ssl from pg_stat_ssl where pid = pg_backend_pid()')->fetchColumn(), true), "\n";
}
