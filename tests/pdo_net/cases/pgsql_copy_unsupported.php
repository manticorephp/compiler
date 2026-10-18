<?php
// A COPY statement run through exec()/prepare() is refused (the COPY API is copyFrom*/copyTo*): php answers HY000 with the libpq result status as the code and keeps the connection usable.
$db = new PDO(getenv('PDO_PG_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
$db->exec('create temp table ct(a int)');
$db->exec('insert into ct values (1), (2)');
$t = microtime(true);
foreach (['copy ct from stdin', 'copy ct to stdout'] as $q) {
    foreach (['exec', 'prepare'] as $how) {
        try {
            if ($how === 'exec') { $db->exec($q); } else { $s = $db->prepare($q); $s->execute(); }
            echo "no error\n";
        } catch (PDOException $e) { echo $how, ' ', json_encode($e->getMessage()), ' ', json_encode($e->errorInfo), "\n"; }
        $s = $db->query('select count(*) from ct'); echo $s->fetchColumn(), "\n"; $s = null;
    }
}
echo microtime(true) - $t < 3.0 ? "fast\n" : "SLOW\n";