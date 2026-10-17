<?php
// Manticore-only: php accepts every option below; the driver refuses what it cannot honour, loudly and at connect/set time.
// Expectation lives in the sibling .expected file.
function mk(array $o): PDO { return new PDO((string) getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
function ctor(string $label, array $o): void {
    try { $d = mk($o); echo $label, ": connected\n"; }
    catch (PDOException $e) { echo $label, ': ', $e->getMessage(), ' ', $e->getCode(), "\n"; }
}
ctor('buffered true', [1000 => true]);
ctor('buffered false', [1000 => false]);
ctor('compress', [1003 => true]);
ctor('compress off', [1003 => false]);
ctor('ssl ca', [1008 => '/nope/ca.pem']);
ctor('ssl verify off', [1013 => false]);
ctor('local infile', [1001 => true]);
$db = mk([]);
try { var_dump($db->setAttribute(1000, true)); } catch (PDOException $e) { echo $e->getMessage(), "\n"; }
try { var_dump($db->setAttribute(1000, false)); } catch (PDOException $e) { echo $e->getMessage(), "\n"; }
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
var_dump($db->setAttribute(1000, false), $db->errorInfo()[0]);
var_dump($db->getAttribute(1000));
