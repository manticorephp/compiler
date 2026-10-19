<?php

/** Stand-in for pdo_mysql.php in a PDO program that never names the mysql driver (see the driver gate in Main.php). */

/** @param array<string,mixed> $options */
function __mc_pdo_mysql_open(string $rest, array $options, ?string $user, ?string $pw): __McPdoDrv
{
    $e = new PDOException('could not find driver (pdo_mysql is not linked: every `new PDO(..)` in the program spells another driver in a literal DSN; build the DSN from a variable or spell "mysql:" in a literal one)', 0);
    $e->errorInfo = __McPdo::triple('', 0, false, '', false);
    throw $e;
}
