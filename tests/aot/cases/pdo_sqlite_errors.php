<?php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT UNIQUE)');
$db->exec("INSERT INTO t VALUES (1,'a')");

echo "-- clean state --\n";
var_dump($db->errorCode(), $db->errorInfo());

echo "-- EXCEPTION mode --\n";
try { $db->exec('SELEC 1'); } catch (PDOException $e) {
    echo $e->getMessage(), "\n"; var_dump($e->errorInfo);
}
try { $db->exec("INSERT INTO t VALUES (1,'z')"); } catch (PDOException $e) {
    echo $e->getMessage(), "\n"; var_dump($e->errorInfo);
}
try { $db->exec("INSERT INTO nope (x) VALUES (1)"); } catch (PDOException $e) {
    echo $e->getMessage(), "\n";
}

echo "-- SILENT mode --\n";
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
var_dump($db->exec('SELEC 1'));
var_dump($db->errorCode());
var_dump($db->errorInfo());
var_dump($db->query('SELEC 1'));
var_dump($db->prepare('SELEC 1'));
var_dump($db->exec("INSERT INTO t VALUES (1,'z')"));
var_dump($db->errorInfo());
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "-- unsupported attribute --\n";
try { $db->getAttribute(PDO::ATTR_TIMEOUT); } catch (PDOException $e) { echo $e->getMessage(), "\n"; }
try { $db->prepare('SELECT 1')->nextRowset(); } catch (PDOException $e) { echo $e->getMessage(), "\n"; }

echo "-- unknown driver --\n";
try { new PDO('bogus:x'); } catch (PDOException $e) {
    echo get_class($e), " ", $e->getMessage(), "\n";
}
echo "-- unopenable file --\n";
try { new PDO('sqlite:/nope/nope/x.db'); } catch (PDOException $e) {
    echo $e->getMessage(), "\n"; var_dump($e->errorInfo);
}
