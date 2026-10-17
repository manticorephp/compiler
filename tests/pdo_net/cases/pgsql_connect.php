<?php
$db = new PDO(getenv('PDO_PG_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw');
echo $db->getAttribute(PDO::ATTR_DRIVER_NAME);
echo $db->query('select 1')->fetchColumn(), "\n";
