<?php
// A literal pgsql DSN links pgsql and not mysql.
try { new PDO('pgsql:host=127.0.0.1;port=1;dbname=d', 'u', 'pw'); echo "connected?\n"; } catch (PDOException $e) { echo "refused\n"; }
var_dump(class_exists('__McPdoPgWire'), class_exists('__McPdoMyWire'));
