<?php
// Manticore-only (php accepts the option and serves the request; this driver cannot): expectation in the sibling .expected file.
// 1001 = MYSQL_ATTR_LOCAL_INFILE
foreach ([true, false] as $v) {
    try { new PDO(getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [1001 => $v]); echo "connected\n"; }
    catch (PDOException $e) { echo $e->getMessage(), ' ', $e->getCode(), "\n"; }
}
